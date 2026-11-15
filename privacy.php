<?php
require __DIR__ . '/inc/bootstrap.php';
layout_start('Privatësia dhe mbrojtja e të dhënave', ['body_class' => current_user() ? '' : 'login-page']);
$ev = setting_int('retention_events_days'); $au = setting_int('retention_audit_days');
?>
<div class="card" style="max-width:860px;margin:<?= current_user() ? '0' : '4vh auto' ?>">
  <h2>🔒 Si i mbrojmë të dhënat e fëmijëve</h2>
  <p><?= e(cfg('app_name')) ?> përdoret nga shkollat për mësimin me libra digjitalë. Të dhënat përpunohen vetëm për qëllime arsimore, në përputhje me Ligjin për Mbrojtjen e të Dhënave Personale të Republikës së Kosovës.</p>
  <h3>Çfarë ruajmë</h3>
  <ul>
    <li><strong>Llogaria e nxënësit:</strong> <?= anon_enabled() ? 'një <strong>kod</strong> (p.sh. PRI-IQ-01-05) në vend të emrit — emrin e vërtetë e mban vetëm mësuesi i klasës, jashtë sistemit' : 'emri' ?>, klasa, shkolla, përdoruesi (pa email, pa numër telefoni, pa datë lindjeje, pa adresë).</li>
    <li><strong>Progresi i mësimit:</strong> faqet e lexuara, koha, ushtrimet dhe përgjigjet, komentet e mësuesit.</li>
    <li><strong>Të dhëna opsionale:</strong> gjinia dhe gjuha amtare (për statistika të përgjithshme).</li>
    <li><strong>Nevojat e veçanta</strong> ruhen <strong>vetëm me pëlqimin me shkrim të prindit</strong>; i shohin vetëm mësuesi i klasës dhe administratori, ndërsa në raporte shfaqen vetëm si numra të përmbledhur. Prindi mund ta tërheqë pëlqimin në çdo kohë — atëherë të dhënat fshihen.</li>
    <li><strong>Përdorimi i veçorive të qasshmërisë</strong> (p.sh. leximi me zë) — për të përmirësuar mbështetjen; i sheh vetëm administratori.</li>
  </ul>
  <h3>Sa kohë i ruajmë</h3>
  <ul>
    <li>Klikimet dhe përdorimi i veçorive fshihen automatikisht pas <strong><?= $ev ?> ditësh</strong>.</li>
    <li>Regjistri i veprimeve të stafit (auditimi) fshihet pas <strong><?= $au ?> ditësh</strong>.</li>
    <li>Llogaritë dhe progresi fshihen kur shkolla e heq nxënësin nga sistemi.</li>
  </ul>
  <h3>Siguria</h3>
  <ul>
    <li>Fjalëkalimet ruhen të enkriptuara (bcrypt); fjalëkalimet fillestare për kartelat ruhen të koduara (AES-256); hyrjet e gabuara bllokohen përkohësisht.</li>
    <li>Në raportet statistikore, grupet me më pak se <?= max(2, small_cell_min()) ?> nxënës nuk shfaqen me numër të saktë, që askush të mos identifikohet.</li>
    <li>Platforma nuk përdor reklama, analitikë apo shërbime të jashtme (Google etj.); të dhënat nuk u jepen palëve të treta.</li>
    <li>Çdo veprim i stafit (shtim, ndryshim, fshirje, eksport) regjistrohet në regjistrin e auditimit.</li>
    <li>Kopje rezervë ditore të bazës ruhen në server në dosje të mbrojtur.</li>
    <li>Linku i prindit është privat dhe mund të anulohet nga mësuesi në çdo kohë.</li>
  </ul>
  <h3>Të drejtat tuaja</h3>
  <p>Prindërit/kujdestarët mund të kërkojnë të shohin, korrigjojnë ose fshijnë të dhënat e fëmijës. Drejtojuni mësuesit të klasës ose drejtorisë së shkollës.</p>
</div>
<?php layout_end();
