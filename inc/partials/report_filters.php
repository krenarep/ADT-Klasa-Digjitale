<?php
/** Filtrat e përbashkët të raporteve. Pret: $from, $to, $extra (HTML i fushave shtesë) */
$sy = (int)date('n') >= 9 ? (int)date('Y') : (int)date('Y') - 1;
$presets = [
    'Viti shkollor ' . $sy . '/' . ($sy + 1) => [$sy . '-09-01', date('Y-m-d')],
    'Gjysmëvjetori i 1-rë' => [$sy . '-09-01', $sy . '-12-31'],
    'Gjysmëvjetori i 2-të' => [($sy + 1) . '-01-01', ($sy + 1) . '-06-30'],
    'Ky muaj' => [date('Y-m-01'), date('Y-m-d')],
    '30 ditët e fundit' => [date('Y-m-d', strtotime('-29 day')), date('Y-m-d')],
    '7 ditët e fundit' => [date('Y-m-d', strtotime('-6 day')), date('Y-m-d')],
];
?>
<section class="card no-print">
  <form method="get" class="form-inline" id="repForm">
    <label>Nga data<input type="date" name="from" value="<?= e($from) ?>" required></label>
    <label>Deri më<input type="date" name="to" value="<?= e($to) ?>" required></label>
    <?= $extra ?>
    <button class="btn btn-primary">📊 Gjenero raportin</button>
  </form>
  <div class="row mt">
    <span class="small muted">Periudha të shpejta:</span>
    <?php foreach ($presets as $l => [$f, $t]): ?>
      <button type="button" class="btn btn-sm btn-ghost" onclick="var F=document.getElementById('repForm');F.from.value='<?= $f ?>';F.to.value='<?= $t ?>';F.submit()"><?= e($l) ?></button>
    <?php endforeach; ?>
  </div>
</section>
