## English summary

**Klasa Digjitale** is a PHP/MySQL platform (Albanian and English UI, with any further language addable and translatable by the administrator) for grades 3–5 that plays ADT Studio, SCORM and HTML books, tracks reading and exercise interactions, and puts the **teacher in the loop**: every automatic result from a book must be confirmed or adjusted by the teacher, and students see their verified grades and comments. It is grounded in **Accessible Digital Textbooks (ADT)** — books with read-aloud, sign-language video, easy-read text, image descriptions, glossary and adjustable display — in **Universal Design for Learning (UDL)** (multiple means of engagement, representation, and action \& expression), in **WCAG** accessibility principles, and in the **SAMR** model for technology integration. It also includes a teacher-journey tracker for the ADT pilot methodology, teacher training certificates and materials, student pseudonymisation, encrypted credentials and small-cell suppression in reports.

Install: copy to your web root → open `install/install.php`. Requirements: PHP 8.1+, MySQL/MariaDB, Apache.

\---

## 

# 📘 Klasa Digjitale

**Platformë mësimi për librat digjitalë të qasshëm (ADT) në shkollat fillore**
*A learning platform for Accessible Digital Textbooks (ADT) in primary schools*

> 💡 Ideuar dhe krijuar nga \*\*\[Krenare Pireva Nuci](https://github.com/krenarep)\*\* · Licenca: \[CC BY-NC-SA 4.0](LICENSE)

\---

## 1\. Çfarë është Klasa Digjitale?

Klasa Digjitale është një platformë web (PHP + MySQL) për klasat III–V. Ajo lidh tri role:

|Roli|Çka bën|
|-|-|
|🧒 **Nxënësi**|Lexon librat digjitalë (ADT Studio / SCORM / HTML), zgjidh ushtrimet, sheh vlerësimet dhe komentet e mësueses, fiton yje dhe distinktivë. Mund të hyjë edhe me **fotografi** (pa fjalëkalim).|
|👩‍🏫 **Mësuesi**|Krijon klasat, ndjek progresin dhe kohën e leximit, **verifikon dhe vlerëson** çdo përgjigje të nxënësit, jep detyra, printon kartela dhe raporte për prindër, ndjek rrugëtimin e vet në pilotim.|
|🛡️ **Administratori**|Menaxhon shkollat, mësuesit, librat, trajnimet dhe certifikatat; sheh të gjithë ndërveprimin nxënës–mësues; raportet statistikore; mbrojtjen e të dhënave.|

Ndërfaqja është në **shqip** dhe **anglisht**, dhe mund të përkthehet në çdo gjuhë tjetër nga vetë administratori (shih *Shumëgjuhësia*). Platforma është ndërtuar me parimet e **Dizajnit Universal për të Nxënë (UDL)**: shkronja të mëdha, kontrast i lartë, lexim me zë, navigim me tastierë dhe ekrane të thjeshta për fëmijë.

\---

## 2\. Konteksti pedagogjik: ADT, qasshmëria dhe UDL

### 📖 Çfarë janë librat ADT?

**ADT (Accessible Digital Textbooks – Libra Digjitalë të Qasshëm)** janë tekste mësimore të shndërruara në format digjital që mund ta përdorë **çdo fëmijë**, përfshirë fëmijët me vështirësi në shikim, dëgjim, lexim (p.sh. disleksi), të nxënë apo komunikim. Ideja është që i njëjti libër t'u shërbejë të gjithë nxënësve në të njëjtën klasë, në vend që fëmijët me nevoja të veçanta të kenë materiale të ndara.

Librat krijohen me **ADT Studio** dhe zakonisht ofrojnë:

|Veçoria|Kujt i ndihmon|
|-|-|
|🔊 **Lexim me zë** (tekst-në-të-folur) me theksim të fjalës që lexohet|nxënësve me vështirësi në lexim ose shikim, lexuesve fillestarë|
|🤟 **Video në gjuhën e shenjave**|nxënësve të shurdhër ose me dëgjim të dëmtuar|
|📝 **Tekst i thjeshtuar (easy-read)** dhe shpjegime të shkurtra|nxënësve me vështirësi në të nxënë ose në gjuhë|
|🖼️ **Përshkrimi i figurave** (tekst alternativ)|nxënësve të verbër ose me shikim të dobët|
|📚 **Fjalori** i fjalëve të reja, **ndarja në rrokje**|të gjithë nxënësve, sidomos atyre që mësojnë të lexojnë|
|🔠 **Madhësia e shkronjave, ngjyrat, kontrasti**, hapësira mes rreshtave|nxënësve me shikim të dobët, disleksi ose vështirësi në përqendrim|
|⌨️ **Navigim me tastierë** dhe me lexues ekrani|nxënësve që nuk përdorin maus ose prekje|
|✍️ **Ushtrime interaktive** (kuize, plotëso vendet bosh, renditje, përgjigje të hapura) me reagim të menjëhershëm|të gjithë nxënësve — mësim aktiv dhe vetëkontroll|

### ♿ Qasshmëria në Klasa Digjitale

Qasshmëria nuk ndalet te libri; e gjithë platforma është ndërtuar që fëmija ta përdorë vetë:

* **Hyrje me fotografi** (kodi i klasës + 3 figura) për fëmijët që ende nuk shkruajnë fjalëkalime.
* Butona të mëdhenj, tekst i qartë, ikona pranë çdo fjale, **A− / A+**, **kontrast i lartë** dhe **🔊 lexim me zë** i faqeve të nxënësit.
* Navigim i plotë me **tastierë**, fokus i dukshëm, etiketa për **lexuesit e ekranit**, respektim i cilësimit «më pak lëvizje».
* Funksionon në **tablet, celular dhe kompjuter**, edhe **pa internet**.
* Platforma regjistron cilat veçori të qasshmërisë përdoren (lexim me zë, gjuha e shenjave, fjalori…), që mësuesit dhe administratori të shohin **çka u ndihmon realisht nxënësve**.

Platforma ndjek parimet e udhëzimeve ndërkombëtare për qasshmërinë në ueb (**WCAG**): e perceptueshme, e përdorshme, e kuptueshme dhe e qëndrueshme.

### 🧩 Dizajni Universal për të Nxënë (UDL)

**UDL (Universal Design for Learning)**, i zhvilluar nga **CAST**, nis nga ideja se nuk ka «nxënës mesatar»: mësimi planifikohet që në fillim me shumë rrugë hyrjeje, në vend që të përshtatet më vonë për disa fëmijë. UDL ka tri parime, dhe Klasa Digjitale i mbështet kështu:

|Parimi i UDL|Pyetja|Si e mbështet Klasa Digjitale|
|-|-|-|
|**1. Mënyra të shumëfishta të angazhimit**|*Pse* mësojmë?|yje, distinktivë, certifikata, vlerësime me fytyra dhe komente nga mësuesja, detyra me afate, reagim i menjëhershëm, zgjedhje e ritmit vetjak|
|**2. Mënyra të shumëfishta të paraqitjes**|*Çka* mësojmë?|teksti + zëri + figurat + gjuha e shenjave + teksti i thjeshtuar + fjalori në të njëjtin libër ADT|
|**3. Mënyra të shumëfishta të veprimit dhe të shprehjes**|*Si* e tregojmë çka dimë?|përgjigje me klikim, me shkrim, me renditje, me tërheqje; shënime personale; mësuesja vlerëson edhe përgjigjet e hapura, jo vetëm ato me një përgjigje të saktë|

### 🔁 Integrimi i teknologjisë: modeli SAMR

Në trajnimet e mësuesve përdoret modeli **SAMR** (Puentedura) për të planifikuar mësimin me libra ADT:

|Niveli|Shembull me Klasa Digjitale|
|-|-|
|**S – Zëvendësim**|libri digjital lexohet në vend të librit në letër|
|**A – Shtim**|lexim me zë, fjalor, madhësi shkronjash — libri bën më shumë se letra|
|**M – Modifikim**|ushtrime interaktive me reagim të menjëhershëm; mësuesja sheh përgjigjet në kohë reale dhe i vlerëson|
|**R – Ridefinim**|çdo fëmijë, me ose pa nevoja të veçanta, mëson me të njëjtin libër në të njëjtën klasë; mësimi diferencohet sipas të dhënave për çdo nxënës|

### 👩‍🏫 Mësuesi në qendër (human-in-the-loop)

Teknologjia ndihmon, por **vendimi pedagogjik mbetet te mësuesi**. Çdo rezultat automatik i librit konsiderohet propozim: mësuesja e konfirmon ose e ndryshon, dhe i shkruan nxënësit koment. Kështu vlerësimi mbetet i drejtë edhe për përgjigjet krijuese, të hapura ose të shprehura ndryshe — gjë thelbësore për arsimin gjithëpërfshirës.

### 🌍 Arsimi gjithëpërfshirës

Qëllimi i projektit është që **asnjë fëmijë të mos mbetet jashtë**: fëmijët me nevoja të veçanta arsimore mësojnë bashkë me shokët e tyre, me të njëjtin libër, ndërsa mësuesit marrin mjete për ndjekjen, vlerësimin dhe mbështetjen e secilit nxënës — duke ruajtur njëkohësisht privatësinë e fëmijëve.

\---

## 3\. Modulet kryesore

### 📚 Librat digjitalë

* Ngarkim i librave **ADT Studio**, **SCORM** ose **HTML** (deri 600 MB, me ngarkim në pjesë dhe shirit progresi).
* Librat globalë caktohen automatikisht te klasat e nivelit përkatës.
* Lexuesi ndjek faqet e lexuara, kohën, klikimet dhe veçoritë e qasshmërisë (lexim me zë, gjuha e shenjave, fjalori, etj.).
* Punon edhe **pa internet** (PWA): puna ruhet në pajisje dhe dërgohet kur kthehet lidhja.

### ✍️ Vlerësimi me mësuesin në qendër (human-in-the-loop)

* Ruhet **çdo ndërveprim** i nxënësit me ushtrimet: kuizet që tregojnë menjëherë saktë/gabim, përgjigjet e shkruara që dërgohen me «Dërgo», renditjet, si dhe tekstet dhe shënimet që ruhen automatikisht.
* Rezultati i librit është vetëm **propozim automatik**: mësuesja e **konfirmon ✔** ose e **ndryshon ✍️** dhe lë koment.
* Vlerësimet janë të grupuara sipas nxënësve, në rreshta të palosshëm.
* Nxënësi i sheh vlerësimet e verifikuara te **⭐ Vlerësimet** (listë interaktive me yje, komente dhe filtra).
* Administratori sheh gjithë ndërveprimin nxënës–mësues (vetëm lexim).

### 🧭 Rrugëtimi i mësuesit (metodologjia e pilotimit)

Shirit progresi me fazat: **Pëlqimet → Pyetësori para → Trajnimi 2-ditor → Pyetësori pas → Projekti (10 plane mësimore, instrumentet, validimi i librit) → Intervenimi (cikle pre-test → aktivitet → post-test → ditar)**.
Mësuesit ngarkojnë dokumentet; administratori i shqyrton («Përfunduar me sukses», «Me komente», «Ka nevojë për punë»), vendos linqet e pyetësorëve (Google Forms) dhe dërgon njoftime.

### 🎓 Trajnimet dhe certifikatat e mësuesve

* Sesione trajnimi me pjesëmarrje, pre/post test dhe kënaqësi.
* **Certifikatë pjesëmarrjeje** (një për mësues, edhe kur trajnimi ka disa sesione), **e editueshme plotësisht** me klikim, me logot e partnerëve.
* **Materialet e trajnimit** (PDF, Word, PowerPoint…) për pjesëmarrësit.

### 🛡️ Mbrojtja e të dhënave të fëmijëve

* **Pseudonimizim**: nxënësit ruhen vetëm me kod (p.sh. `PRI-YM-01-05`); emrat e vërtetë i mban mësuesi në listën-çelës.
* Kodi i nxënësit nuk mund të rishkruhet me emër; numërimi plotëson vendet e lira.
* Fjalëkalimet fillestare të nxënësve ruhen të **koduara** (AES-256-GCM).
* **Shtypja e grupeve të vogla** në raporte (p.sh. «<5»).
* Pëlqimi i prindit për të dhënat e nevojave të veçanta, regjistër i veprimeve të stafit, fshirje automatike sipas afatit, kopje rezervë.
* Në tabletët e përbashkët, puna e një fëmije nuk i shfaqet fëmijës tjetër.

### 🌐 Shumëgjuhësia (si librat ADT)

* Çdo përdorues zgjedh **gjuhën e ndërfaqes** (te faqja e hyrjes ose në meny) dhe zgjedhja i ruhet.
* Vjen me **shqip** (gjuha burimore) dhe **anglisht**; administratori **shton çdo gjuhë tjetër** (serbisht, turqisht, boshnjakisht, romani…) te **🌐 Gjuhët**.
* Tekstet e platformës nxirren automatikisht nga kodi; përkthehen në faqe, ose eksportohen në **Excel/CSV** për një përkthyes dhe importohen sërish.
* Numrat dhe emrat ruhen me `{x}` («Ke {x} vlerësime» → «You have {x} grades»); mbështetet edhe shkrimi nga e djathta në të majtë.
* Librat ADT kanë gjuhët e tyre brenda librit; ndërfaqja e platformës dhe libri mund të jenë në gjuhë të ndryshme.

### 📊 Raportet

Raporte statistikore sipas komunës, shkollës, gjinisë dhe nevojave të veçanta; raporti për palët (EN, .docx); raporti mujor për prindër; certifikatat e nxënësve me kod verifikimi.

\---

## 4\. Kërkesat teknike

* **PHP 8.1+** me `pdo\_mysql`, `zip`, `mbstring`, `openssl`, `simplexml`
* **MySQL 5.7+** ose **MariaDB 10.3+**
* **Apache** me `.htaccess` (rekomandohet), HTTPS për funksionin pa internet
* Punon në **XAMPP** dhe në hosting të zakonshëm **cPanel**

## 5\. Instalimi

1. Kopjoni dosjen në `htdocs/` (XAMPP) ose në `public\_html/` (cPanel).
2. Bëni të shkrueshme dosjet `content/books/` dhe `backups/`.
3. Hapni `…/install/install.php`, shkruani të dhënat e bazës dhe llogarinë e parë të administratorit. Instaluesi krijon tabelat dhe `config.php`.
4. Fshini ose mbroni dosjen `install/`.
5. Për libra të mëdhenj, rritni limitet në `php.ini`: `upload\_max\_filesize = 600M`, `post\_max\_size = 620M`, `max\_execution\_time = 900`.

## 6\. Përditësimi

1. Ngarkoni skedarët e rinj mbi të vjetrit (**ruani** `config.php`, `backups/` dhe `content/books/`).
2. Hyni si administrator → **🗄️ Përditëso bazën**. Shtohen vetëm tabelat/kolonat që mungojnë; të dhënat ekzistuese nuk preken.

## 7\. Siguria e këtij depoje

Ky depo **nuk përmban** asnjë të dhënë shkolle, mësuesi apo nxënësi, asnjë fjalëkalim, çelës kodimi, kopje rezervë apo faqe testimi.

|Nuk publikohet|Pse|
|-|-|
|`config.php`|fjalëkalimi i bazës së të dhënave (krijohet nga instaluesi)|
|`backups/`|çelësat `.app\_key` / `.anon\_key`, kopjet rezervë, dokumentet e ngarkuara|
|`content/books/`|librat e ngarkuar|

> ⚠️ Kur e zhvendosni një instalim ekzistues, kopjoni nga serveri i vjetër `config.php` \*\*dhe\*\* `backups/`. Pa `backups/.app\_key` fjalëkalimet e koduara të nxënësve nuk mund të lexohen.

## 8\. Struktura e dosjeve

```
admin/      faqet e administratorit
teacher/    faqet e mësuesit
student/    faqet e nxënësit dhe lexuesi i librave
api/        ndjekja e leximit, ngarkimi në pjesë, skedarët e librave
inc/        logjika (përdoruesit, librat, vlerësimi, rrugëtimi, certifikatat, raportet, shumëgjuhësia, migrimet…)
inc/lang/   paketat e gatshme të përkthimeve (en.json)
install/    instaluesi dhe skema e bazës
assets/     CSS, JavaScript, fontet
tools/      detyrat ditore (cron) dhe importi i librave nga komanda
```

\---

## Autorësia dhe licenca

**Ideuar dhe krijuar nga Krenare Pireva Nuci** — [github.com/krenarep](https://github.com/krenarep). Shih [CREDITS.md](CREDITS.md).

Licencuar sipas [**Creative Commons BY-NC-SA 4.0**](LICENSE): mund ta përdorni, ndryshoni dhe shpërndani për qëllime **jo-komerciale**, duke **përmendur autoren** dhe duke shpërndarë versionet e ndryshuara me **të njëjtën licencë**.

[!\[CC BY-NC-SA 4.0](https://licensebuttons.net/l/by-nc-sa/4.0/88x31.png)](https://creativecommons.org/licenses/by-nc-sa/4.0/)

