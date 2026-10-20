package net.jhead.ws3.database;

import java.util.Properties;

/**
 * A MunkaLap 3 adatbázisa a saját bejelentkeztetőjét kéri (derby.authentication.provider). Az átalakító a
 * felhasználó SAJÁT mentését olvassa csak olvasásra, egy ideiglenes másolaton – ehhez a bejelentkeztető helyett
 * ez az egyszerű, mindent engedő változat áll be. Az eredeti mentéshez nem nyúl.
 */
public class DerbyUserManager implements org.apache.derby.authentication.UserAuthenticator {
    public boolean authenticateUser(String felhasznalo, String jelszo, String adatbazis, Properties info) {
        return true;
    }
}
