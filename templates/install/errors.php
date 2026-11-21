<?php
/** @var League\Plates\Template\Template $this */
/** @var list<string> $errors */
?>
<?php if ($errors !== []): ?>
<div class="alert alert-stop">
<ul>
<?php foreach ($errors as $error): ?>
    <li><?= $this->e($error) ?></li>
<?php endforeach ?>
</ul>
</div>
<?php endif ?>
