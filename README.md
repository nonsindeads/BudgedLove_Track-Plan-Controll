# 🌋 Der Waldbrand – Offizieller Hytale Public RPG Server

Willkommen bei **Der Waldbrand**! Unser Server kombiniert anspruchsvolles Progression-RPG, Berufe, Quests und freies Bauen auf Grundstücken/Plots mit einer stabilen, kontrollierten Wirtschaft.

## 🔗 Server-Informationen & Direktverbindung
- **Server-Name:** Der Waldbrand
- **IP-Adresse:** 65.109.56.119:5520 (Server 20)
- **Max. Spieler:** 25
- **MOTD:** Oeffentlicher RPG-Server: Hub, Bauwelt, Quests und schwere Abenteuerwelten.

---

## 🌟 Server-Features & Konzept

### ⚔️ Progression & Kampf (`Natural20`)
- **System:** Natural20 bildet die zentrale Schicht für Kampfstufe, Party-System, Quests und Dungeons.
- **Gruppenspiel:** Gemeinsamer Loot und Shared Killcredit auf schwere Bosse.

### ⛏️ Berufe & Skills (`MMO Skill Tree`)
- **Aktive Berufe:** Bergbau, Holzfällen, Ausgraben, Ernten, Angeln, Handwerk, Verzaubern und Bauen.
- **Skill-Menü:** Öffnen per Ingame-Befehl `/skills`.

### 💰 Kontrollierte Belohnungs-Wirtschaft (`GlymeraMerchant`)
- **Inflationsschutz:** Gewöhnliche Erze, Grundmaterialien, Baumaterialien und Standard-Waffen können nicht an Händler verkauft werden (`0 Gold`).
- **Belohnungs-Ankauf:** Gold wird durch seltene Trophäen, seltene Fische, Aetherhaven-Münzen und Quests verdient.
- **Gold-Abfrage:** Guthaben jederzeit einsehbar per `/money`.

### 🏝️ Hub & Händler-Marktplatz (`himmelsinsel`)
- **Zentraler Platz:** Schwebender Insel-Hub mit allen 6 Haupt-Händlern (Schmied, Proviant, Alchemist, Taverne, Baumeister, Kuriositäten).
- **Schnellreise:** Teleport jederzeit per `/warp markt`.

### 🏰 Plot- & Bauwelt (`Aetherhaven` / `Claims`)
- **Eigenes Grundstück:** Plotwelt für den Bau von Häusern, Siedlungen und Werkstätten.
- **Landclaiming:** Schutz vor Griefing über das integrierte Claim-System.

---

## 📜 Offizielle Server-Regeln

### 1. 🛡️ Fairness & Anti-Cheat
- Hacks, Modded Clients (mit Bewegungsvorteilen), Duplikations-Exploits und automatisierte AFK-Bots sind strengstens verboten.
- Bugs und Glitches müssen umgehend gemeldet werden. Ausnutzen von Fehlern führt zum Bann.

### 2. 🏗️ Bauen & GS-Schutz
- Das Verändern, Zerlegen oder Bebauen fremder Grundstücke/Plots ohne Rechte ist untersagt.
- Griefing in der Bauwelt oder um geschützte Zonen wird nicht toleriert.

### 3. 💰 Wirtschaft & Handel
- Handelsabzocke oder Täuschung im Spieler-zu-Spieler-Handel ist verboten.
- Die Server-Händler kaufen nur spezifische Trophäen und seltene Fische an. Erze und einfache Rohstoffe haben keinen Ankaufswert.

### 4. 💬 Chat & Verhalten
- Respektvoller Umgangston im Chat. Diskriminierung, Beleidigungen, Spam oder Fremdwerbung führen zum Mute oder Ausschluss.

### 5. ⚔️ PVP & Welten
- Hauptwelt & Hub (`himmelsinsel`) sind friedliche Zonen. Kämpfe und Abenteuer finden in den dafür vorgesehenen Dungeon- & Spezialwelten statt.

---

## 📜 Kompakte Spieler-Befehlsliste

### 🏠 Teleportation & Navigation
| Befehl | Beschreibung |
| :--- | :--- |
| **`/spawn`** | Teleportiert dich direkt zum Haupt-Spawn. |
| **`/warp markt`** | Teleportiert dich auf den Händler-Marktplatz auf der Himmelsinsel. |
| **`/warp bauwelt`** | Teleportiert dich in die Plot- & Bauwelt. |
| **`/sethome <name>`** | Setzt einen persönlichen Teleport-Punkt. |
| **`/home <name>`** | Teleportiert dich zu deinem gesetzten Home-Punkt. |
| **`/tp world <weltname>`** | Wechselt in freigegebene Welten (z. B. `/tp world default`). |

### 💰 Wirtschaft & Handel
| Befehl | Beschreibung |
| :--- | :--- |
| **`/money`** *(oder `/balance`)* | Zeigt dein aktuelles Goldguthaben an. |
| **`/pay <spieler> <betrag>`** | Überweist Gold sicher an einen Mitspieler. |
| **`/shop <typ>`** | Öffnet den Händler-Shop (z. B. `/shop smith`), wenn man am Stand steht. |

### 🏗️ Bauwelt & Plot-Verwaltung
| Befehl | Beschreibung |
| :--- | :--- |
| **`/plot`** | Öffnet das Plot-Hauptmenü. |
| **`/plot auto`** | Weist dir automatisch das nächste freie Grundstück zu. |
| **`/plot claim`** | Beansprucht das Grundstück, auf dem du gerade stehst. |
| **`/plot add <spieler>`** | Gibt einem Mitspieler Baurechte auf deinem Plot. |
| **`/plot remove <spieler>`** | Entzieht einem Spieler die Baurechte auf deinem Plot. |
| **`/plot home`** | Teleportiert dich direkt zu deinem eigenen Plot. |

### ⚔️ Charakter, Berufe & Inventar
| Befehl | Beschreibung |
| :--- | :--- |
| **`/skills`** | Öffnet das MMO Skill-Tree-Menü (Berufe, XP, Talentbäume). |
| **`/quest`** | Zeigt deine aktiven Aufgaben und Fortschritte. |
| **`/party`** | Öffnet das Gruppen- und Party-System für gemeinsamen Loot. |
| **`/trash`** | Öffnet ein Müllfenster zum schnellen Entsorgen von Müll-Items. |

---

## 📝 Admin Ingame-Vorbereitungsliste
1. `/tp world himmelsinsel` -> Warp setzen mit `/warp set markt` & `/warp set spawn`.
2. Alle 6 Händler spawnen (`/merchant spawn smith`, `/merchant spawn provisions`, `/merchant spawn alchemist`, `/merchant spawn tavern`, `/merchant spawn builder`, `/merchant spawn curios`).
3. Bauwelt initialisieren mit `/plotworld setup` und Warp setzen mit `/warp set bauwelt`.
