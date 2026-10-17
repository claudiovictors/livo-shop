<?php $this->layout('layouts/main') ?>
<h1><?= $this->e($name) ?></h1><?= $this->csrfField() ?><?php $this->partial('part', ['n' => 2]) ?>
