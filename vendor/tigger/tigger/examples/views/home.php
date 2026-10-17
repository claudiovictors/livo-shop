<h1>Olá, <?= $this->e($name) ?>!</h1>
<form method="post" action="/checkout">
  <?= $this->csrfField() ?>
  <button>Finalizar compra</button>
</form>
