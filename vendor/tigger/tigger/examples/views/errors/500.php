<h1>Algo correu mal</h1>
<p>Já fomos avisados. Tenta novamente daqui a pouco.</p>
<?php if ($debug && $exception !== null): ?>
  <pre><?= $this->e($exception->getMessage()) ?></pre>
<?php endif; ?>
