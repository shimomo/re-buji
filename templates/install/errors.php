<?php
/** @var League\Plates\Template\Template $this */
/** @var list<string> $errors */
?>
<?php if ($errors !== []): ?>
<ul class="error">
<?php foreach ($errors as $error): ?>
    <li><?= $this->e($error) ?></li>
<?php endforeach ?>
</ul>
<?php endif ?>
