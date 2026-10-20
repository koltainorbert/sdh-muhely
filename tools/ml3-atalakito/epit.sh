#!/bin/sh
# A MunkaLap 3 átalakító (assets/ml3/munkalap-atalakito.jar) építése. Kell hozzá egy JDK (11+) és a MunkaLap 3
# lib/derby.jar-ja (csak fordításhoz – a jar-ba NEM kerül bele; futáskor a MunkaLap saját derby.jar-ja kell).
# Használat: tools/ml3-atalakito/epit.sh /út/a/derby.jar
set -e
cd "$(dirname "$0")"
DERBY="${1:?Add meg a derby.jar útját}"
rm -rf out && mkdir out
javac -encoding UTF-8 --release 8 -nowarn -cp "$DERBY" -d out src/MunkaLapAtalakito.java src/net/jhead/ws3/database/DerbyUserManager.java
printf 'Main-Class: MunkaLapAtalakito\n' > out/manifest.txt
jar cfm ../../assets/ml3/munkalap-atalakito.jar out/manifest.txt -C out MunkaLapAtalakito.class -C out net
rm -rf out
echo "kész: assets/ml3/munkalap-atalakito.jar"
