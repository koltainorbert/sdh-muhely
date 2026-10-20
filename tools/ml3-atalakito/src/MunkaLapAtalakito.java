import java.io.*;
import java.nio.charset.StandardCharsets;
import java.nio.file.*;
import java.security.MessageDigest;
import java.sql.*;
import java.util.*;
import java.util.zip.*;

/**
 * MunkaLap 3 mentés (.wdg / Derby adatbázis-mappa) → hordozható csomag (ZIP: manifest.json, tables/*.jsonl, files/...).
 *
 * Használat: java -cp "atalakito.jar;<MunkaLap>\lib\derby.jar;<MunkaLap>\lib\derbyshared.jar" MunkaLapAtalakito
 *            <bemenet.wdg | adatbázis-mappa> <kimenet.zip> [--allapot allapot.json] [--temp mappa]
 *
 * Az eredeti mentéshez nem nyúl: a .wdg-t ideiglenes mappába bontja, és azt nyitja meg (a Derby helyreállítja a naplóból).
 * A bélyegképeket (LARGE/SMALL_THUMBNAIL – a tartalomból újragenerálható) és a jelszó-kivonatokat nem viszi át.
 */
public final class MunkaLapAtalakito {
    static final String VERZIO = "1.0.0";
    static final Set<String> KIHAGY = new HashSet<>(Arrays.asList(
            "LARGE_THUMBNAIL", "SMALL_THUMBNAIL", "USERS.USER_DATA.PASSWORD", "USERS.USER_DATA.OTP"));

    static Path allapotFajl;
    static long indult = System.currentTimeMillis();

    public static void main(String[] args) throws Exception {
        if (args.length < 2) {
            System.err.println("Használat: MunkaLapAtalakito <bemenet.wdg|mappa> <kimenet.zip> [--allapot f.json] [--temp mappa]");
            System.exit(2);
        }
        Path be = Paths.get(args[0]).toAbsolutePath();
        Path ki = Paths.get(args[1]).toAbsolutePath();
        Path temp = null;
        for (int i = 2; i < args.length - 1; i++) {
            if ("--allapot".equals(args[i])) allapotFajl = Paths.get(args[++i]);
            else if ("--temp".equals(args[i])) temp = Paths.get(args[++i]);
        }
        try {
            futtat(be, ki, temp);
        } catch (Throwable e) {
            allapot("hiba", 0, 0, "", hibaSzoveg(e));
            e.printStackTrace();
            System.exit(1);
        }
    }

    static String hibaSzoveg(Throwable e) {
        StringBuilder sb = new StringBuilder();
        for (Throwable t = e; t != null; t = t.getCause()) {
            if (sb.length() > 0) sb.append(" | ");
            sb.append(t.getClass().getSimpleName()).append(": ").append(t.getMessage());
            if (t instanceof SQLException && ((SQLException) t).getNextException() != null) {
                sb.append(" | ").append(((SQLException) t).getNextException().getMessage());
            }
        }
        return sb.toString();
    }

    static void futtat(Path be, Path ki, Path temp) throws Exception {
        Path munka = Files.createTempDirectory(temp != null ? temp : ki.toAbsolutePath().getParent(), "mlatalakito");
        Map<String, String> meta = new LinkedHashMap<>();
        try {
            Path db;
            if (Files.isDirectory(be)) {
                Path forras = Files.exists(be.resolve("service.properties")) ? be : be.resolve("database");
                allapot("masolas", 0, 0, "", null);
                db = munka.resolve("database");
                masolMappa(forras, db);
            } else {
                allapot("kibontas", 0, 0, "", null);
                kibont(be, munka, meta);
                db = munka.resolve("database");
            }
            if (!Files.exists(db.resolve("service.properties"))) {
                throw new IllegalStateException("A mentésben nincs MunkaLap adatbázis (database/service.properties).");
            }
            System.setProperty("derby.system.home", munka.toString());
            System.setProperty("derby.stream.error.file", munka.resolve("derby.log").toString());
            System.setProperty("derby.authentication.provider", "net.jhead.ws3.database.DerbyUserManager");
            allapot("megnyitas", 0, 0, "", null);
            for (String d : new String[]{"org.apache.derby.iapi.jdbc.AutoloadedDriver", "org.apache.derby.jdbc.AutoloadedDriver", "org.apache.derby.jdbc.EmbeddedDriver"}) {
                try { Class.forName(d); break; } catch (ClassNotFoundException ignored) { }
            }
            try (Connection c = DriverManager.getConnection("jdbc:derby:" + db.toString().replace('\\', '/'))) {
                c.setReadOnly(true);
                exportal(c, ki, meta);
            }
            try { DriverManager.getConnection("jdbc:derby:;shutdown=true"); } catch (SQLException ignored) { }
        } finally {
            torolMappa(munka);
        }
    }

    /* ---------------- export ---------------- */

    static void exportal(Connection c, Path ki, Map<String, String> meta) throws Exception {
        DatabaseMetaData md = c.getMetaData();
        List<String[]> tablak = new ArrayList<>();
        try (ResultSet t = md.getTables(null, null, "%", new String[]{"TABLE"})) {
            while (t.next()) {
                String s = t.getString(2);
                if (s.startsWith("SYS")) continue;
                tablak.add(new String[]{s, t.getString(3)});
            }
        }
        tablak.sort(Comparator.comparing(a -> a[0] + "." + a[1]));

        // sorok száma előre (a folyamatjelzőhöz)
        long osszes = 0;
        Map<String, Long> darab = new LinkedHashMap<>();
        for (String[] t : tablak) {
            try (Statement s = c.createStatement(); ResultSet r = s.executeQuery("SELECT COUNT(*) FROM " + q(t[0]) + "." + q(t[1]))) {
                r.next();
                darab.put(t[0] + "." + t[1], r.getLong(1));
                osszes += r.getLong(1);
            }
        }

        Path ideiglenes = ki.resolveSibling(ki.getFileName() + ".resz");
        StringBuilder man = new StringBuilder();
        man.append("{\"formatum\":\"munkalap3-csomag\",\"atalakito\":\"").append(VERZIO).append("\",\"keszult\":\"")
           .append(new Timestamp(System.currentTimeMillis())).append("\",\"forras\":{");
        boolean elso = true;
        for (Map.Entry<String, String> e : meta.entrySet()) {
            if (!elso) man.append(',');
            elso = false;
            man.append(js(e.getKey())).append(':').append(js(e.getValue()));
        }
        man.append("},\"tablak\":[");

        long kesz = 0;
        long fajlDb = 0, fajlBajt = 0;
        try (ZipOutputStream zip = new ZipOutputStream(new BufferedOutputStream(Files.newOutputStream(ideiglenes), 1 << 20))) {
            zip.setLevel(6);
            int ti = 0;
            for (String[] t : tablak) {
                String nev = t[0] + "." + t[1];
                List<String> pk = new ArrayList<>();
                try (ResultSet p = md.getPrimaryKeys(null, t[0], t[1])) {
                    while (p.next()) pk.add(p.getString(4));
                }
                List<String[]> oszlopok = new ArrayList<>(); // név, típus
                try (ResultSet col = md.getColumns(null, t[0], t[1], "%")) {
                    while (col.next()) oszlopok.add(new String[]{col.getString(4), col.getString(6), String.valueOf(col.getInt(7))});
                }
                List<String[]> kivalasztott = new ArrayList<>();
                for (String[] o : oszlopok) {
                    if (KIHAGY.contains(o[0]) || KIHAGY.contains(nev + "." + o[0])) continue;
                    kivalasztott.add(o);
                }
                StringBuilder sql = new StringBuilder("SELECT ");
                for (int i = 0; i < kivalasztott.size(); i++) {
                    if (i > 0) sql.append(',');
                    sql.append(q(kivalasztott.get(i)[0]));
                }
                sql.append(" FROM ").append(q(t[0])).append('.').append(q(t[1]));
                if (!pk.isEmpty()) {
                    sql.append(" ORDER BY ");
                    for (int i = 0; i < pk.size(); i++) {
                        if (i > 0) sql.append(',');
                        sql.append(q(pk.get(i)));
                    }
                }
                String pkOszlop = pk.size() == 1 ? pk.get(0) : null;
                String extOszlop = null;
                for (String[] o : kivalasztott) if ("EXTENSION".equals(o[0])) extOszlop = "EXTENSION";

                Path jsonl = Files.createTempFile(ideiglenes.getParent(), "tabla", ".jsonl");
                long sorok = 0, tFajl = 0;
                MessageDigest tablaHash = MessageDigest.getInstance("SHA-1");
                try (Writer w = new BufferedWriter(new OutputStreamWriter(Files.newOutputStream(jsonl), StandardCharsets.UTF_8), 1 << 20);
                     Statement st = c.createStatement(ResultSet.TYPE_FORWARD_ONLY, ResultSet.CONCUR_READ_ONLY)) {
                    st.setFetchSize(500);
                    try (ResultSet r = st.executeQuery(sql.toString())) {
                        while (r.next()) {
                            StringBuilder sor = new StringBuilder(256).append('{');
                            String kulcs = pkOszlop != null ? r.getString(pkOszlop) : String.valueOf(sorok + 1);
                            String ext = extOszlop != null ? r.getString(extOszlop) : null;
                            for (int i = 0; i < kivalasztott.size(); i++) {
                                String[] o = kivalasztott.get(i);
                                if (i > 0) sor.append(',');
                                sor.append(js(o[0])).append(':');
                                String tipus = o[1];
                                if ("BLOB".equals(tipus) || tipus.contains("FOR BIT DATA")) {
                                    try (InputStream in = r.getBinaryStream(i + 1)) {
                                        if (in == null) { sor.append("null"); continue; }
                                        String fnev = "files/" + t[1] + "/" + biztonsagos(kulcs) + "." + o[0]
                                                + (ext != null && !ext.isEmpty() && "CONTENT".equals(o[0]) ? "." + biztonsagos(ext.toLowerCase(Locale.ROOT)) : ".bin");
                                        ZipEntry ze = new ZipEntry(fnev);
                                        zip.putNextEntry(ze);
                                        MessageDigest h = MessageDigest.getInstance("SHA-1");
                                        byte[] buf = new byte[1 << 16];
                                        long meret = 0;
                                        int n;
                                        while ((n = in.read(buf)) > 0) {
                                            zip.write(buf, 0, n);
                                            h.update(buf, 0, n);
                                            meret += n;
                                        }
                                        zip.closeEntry();
                                        tFajl++; fajlDb++; fajlBajt += meret;
                                        sor.append("{\"$fajl\":").append(js(fnev)).append(",\"meret\":").append(meret)
                                           .append(",\"sha1\":\"").append(hex(h.digest())).append("\"}");
                                    }
                                    continue;
                                }
                                String v = r.getString(i + 1);
                                if (v == null) { sor.append("null"); continue; }
                                if ("INTEGER".equals(tipus) || "SMALLINT".equals(tipus) || "BIGINT".equals(tipus)) sor.append(v);
                                else sor.append(js(v));
                            }
                            sor.append("}\n");
                            byte[] b = sor.toString().getBytes(StandardCharsets.UTF_8);
                            tablaHash.update(b);
                            w.write(sor.toString());
                            sorok++;
                            kesz++;
                            if ((kesz & 1023) == 0) allapot("export", kesz, osszes, nev, null);
                        }
                    }
                }
                ZipEntry ze = new ZipEntry("tables/" + nev + ".jsonl");
                zip.putNextEntry(ze);
                Files.copy(jsonl, zip);
                zip.closeEntry();
                Files.deleteIfExists(jsonl);

                if (ti++ > 0) man.append(',');
                man.append("{\"nev\":").append(js(nev)).append(",\"sorok\":").append(sorok).append(",\"fajlok\":").append(tFajl)
                   .append(",\"sha1\":\"").append(hex(tablaHash.digest())).append("\",\"kulcs\":").append(pkOszlop == null ? "null" : js(pkOszlop))
                   .append(",\"oszlopok\":[");
                for (int i = 0; i < kivalasztott.size(); i++) {
                    if (i > 0) man.append(',');
                    String[] o = kivalasztott.get(i);
                    man.append("[").append(js(o[0])).append(',').append(js(o[1])).append(',').append(o[2]).append(']');
                }
                man.append("]}");
                allapot("export", kesz, osszes, nev, null);
            }
            man.append("],\"osszes_sor\":").append(kesz).append(",\"fajlok\":").append(fajlDb).append(",\"fajl_bajt\":").append(fajlBajt).append('}');
            zip.putNextEntry(new ZipEntry("manifest.json"));
            zip.write(man.toString().getBytes(StandardCharsets.UTF_8));
            zip.closeEntry();
        }
        Files.move(ideiglenes, ki, StandardCopyOption.REPLACE_EXISTING);
        allapot("kesz", kesz, osszes, "", null);
        System.out.println("KESZ sorok=" + kesz + " fajlok=" + fajlDb + " bajt=" + fajlBajt + " mp=" + (System.currentTimeMillis() - indult) / 1000);
    }

    /* ---------------- segédek ---------------- */

    static void kibont(Path wdg, Path cel, Map<String, String> meta) throws IOException {
        try (ZipFile z = new ZipFile(wdg.toFile())) {
            ZipEntry m = z.getEntry("Meta.properties");
            if (m != null) {
                Properties p = new Properties();
                try (InputStream in = z.getInputStream(m)) { p.load(in); }
                for (String k : new TreeSet<>(p.stringPropertyNames())) meta.put(k, p.getProperty(k));
            }
            Enumeration<? extends ZipEntry> en = z.entries();
            Path celN = cel.normalize();
            byte[] buf = new byte[1 << 16];
            while (en.hasMoreElements()) {
                ZipEntry e = en.nextElement();
                if (!e.getName().startsWith("database/")) continue;
                Path p = cel.resolve(e.getName()).normalize();
                if (!p.startsWith(celN)) throw new IOException("Érvénytelen útvonal a mentésben: " + e.getName());
                if (e.isDirectory()) { Files.createDirectories(p); continue; }
                Files.createDirectories(p.getParent());
                try (InputStream in = z.getInputStream(e); OutputStream out = Files.newOutputStream(p)) {
                    int n;
                    while ((n = in.read(buf)) > 0) out.write(buf, 0, n);
                }
            }
        }
    }

    static void masolMappa(Path forras, Path cel) throws IOException {
        Files.walk(forras).forEach(p -> {
            try {
                Path c = cel.resolve(forras.relativize(p).toString());
                if (Files.isDirectory(p)) Files.createDirectories(c);
                else if (!p.getFileName().toString().endsWith(".lck")) Files.copy(p, c, StandardCopyOption.REPLACE_EXISTING);
            } catch (IOException e) { throw new UncheckedIOException(e); }
        });
    }

    static void torolMappa(Path p) {
        try {
            if (!Files.exists(p)) return;
            Files.walk(p).sorted(Comparator.reverseOrder()).forEach(x -> { try { Files.delete(x); } catch (IOException ignored) { } });
        } catch (IOException ignored) { }
    }

    static void allapot(String fazis, long kesz, long osszes, String tabla, String hiba) {
        if (allapotFajl == null) return;
        String s = "{\"fazis\":" + js(fazis) + ",\"kesz\":" + kesz + ",\"osszes\":" + osszes + ",\"tabla\":" + js(tabla)
                + ",\"mp\":" + (System.currentTimeMillis() - indult) / 1000 + ",\"hiba\":" + (hiba == null ? "null" : js(hiba)) + "}";
        try {
            Path tmp = allapotFajl.resolveSibling(allapotFajl.getFileName() + ".tmp");
            Files.write(tmp, s.getBytes(StandardCharsets.UTF_8));
            Files.move(tmp, allapotFajl, StandardCopyOption.REPLACE_EXISTING);
        } catch (IOException ignored) { }
    }

    static String q(String s) { return "\"" + s.replace("\"", "\"\"") + "\""; }

    static String biztonsagos(String s) { return s.replaceAll("[^A-Za-z0-9_-]", "_"); }

    static String hex(byte[] b) {
        StringBuilder sb = new StringBuilder();
        for (byte x : b) sb.append(String.format("%02x", x));
        return sb.toString();
    }

    static String js(String s) {
        if (s == null) return "null";
        StringBuilder sb = new StringBuilder(s.length() + 2).append('"');
        for (int i = 0; i < s.length(); i++) {
            char ch = s.charAt(i);
            switch (ch) {
                case '"': sb.append("\\\""); break;
                case '\\': sb.append("\\\\"); break;
                case '\n': sb.append("\\n"); break;
                case '\r': sb.append("\\r"); break;
                case '\t': sb.append("\\t"); break;
                default:
                    if (ch < 0x20 || ch == 0x2028 || ch == 0x2029) sb.append(String.format("\\u%04x", (int) ch));
                    else if (Character.isSurrogate(ch) && !(Character.isHighSurrogate(ch) && i + 1 < s.length() && Character.isLowSurrogate(s.charAt(i + 1)))
                            && !(Character.isLowSurrogate(ch) && i > 0 && Character.isHighSurrogate(s.charAt(i - 1)))) sb.append("\\ufffd");
                    else sb.append(ch);
            }
        }
        return sb.append('"').toString();
    }
}
