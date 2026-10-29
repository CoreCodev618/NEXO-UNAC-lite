<?= $this->extend('layouts/auth') ?>

<?= $this->section('title') ?>Crear cuenta<?= $this->endSection() ?>

<?= $this->section('main') ?>
<?php
$config    = config('Auth');
$errors    = session('errors');
$error     = session('error');
$domains   = \App\Libraries\InstitucionalValidator::domains();
?>
<div class="nx-auth__form-wrap">
    <div class="nx-auth__form-head">
        <span class="nx-auth__eyebrow">Primera vez</span>
        <h2 class="nx-auth__title">Crea tu cuenta.</h2>
        <p class="nx-auth__desc">Solo necesitas tu correo institucional.</p>
    </div>


    <?php if ($error !== null): ?>
        <div class="nx-alert nx-alert--danger" role="alert" style="margin-bottom: 20px;">
            <?= nexo_icon('alert-circle') ?>
            <div class="nx-alert__body"><?= esc($error) ?></div>
        </div>
    <?php elseif (is_array($errors) && $errors !== []): ?>
        <div class="nx-alert nx-alert--danger" role="alert" style="margin-bottom: 20px;">
            <?= nexo_icon('alert-circle') ?>
            <div class="nx-alert__body">
                <span class="nx-alert__title">Revisa los datos</span>
                <?php foreach ($errors as $message): ?>
                    <div><?= esc($message) ?></div>
                <?php endforeach ?>
            </div>
        </div>
    <?php endif ?>

    <form
        class="nx-auth__form"
        action="<?= url_to('register') ?>"
        method="post"
    >
        <?= csrf_field() ?>

        <div class="nx-field">
            <label class="nx-label" for="name">Nombre completo</label>
            <input
                class="nx-input nx-input--lg"
                id="name"
                name="name"
                type="text"
                autocomplete="name"
                placeholder="Nombre y apellido"
                value="<?= esc(old('name') ?? '') ?>"
                maxlength="150"
                required
                autofocus
            >
        </div>

        <div class="nx-field">
            <label class="nx-label" for="email">Correo institucional</label>
            <input
                class="nx-input nx-input--lg"
                id="email"
                name="email"
                type="email"
                inputmode="email"
                autocomplete="email"
                placeholder="nombre@<?= esc($domains[0] ?? '', 'attr') ?>"
                value="<?= esc(old('email') ?? '') ?>"
                required
            >
            <span class="nx-hint">Debes registrarte con tu correo @<?= esc(implode(' o @', $domains)) ?>.</span>
        </div>

        <div class="nx-field">
            <label class="nx-label" for="password">Contraseña</label>
            <div class="nx-auth__password">
                <input
                    class="nx-input nx-input--lg"
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="new-password"
                    placeholder="Mínimo 8 caracteres"
                    minlength="8"
                    required
                    data-password-input
                    data-password-strength-input
                >
                <button class="nx-auth__password-toggle" type="button" data-password-toggle aria-label="Mostrar contraseña" aria-pressed="false">
                    <?= nexo_icon('eye') ?>
                </button>
            </div>
            <div class="nx-auth__strength" data-password-strength data-score="0" aria-hidden="true">
                <span></span><span></span><span></span><span></span>
            </div>
            <span class="nx-hint" data-password-strength-label>Usa una frase larga o una combinación de letras, números y símbolos.</span>
            <span class="nx-auth__caps" data-caps-warning>Bloq Mayús está activado.</span>
        </div>

        <div class="nx-field">
            <label class="nx-label" for="password_confirm">Confirma la contraseña</label>
            <input
                class="nx-input nx-input--lg"
                id="password_confirm"
                name="password_confirm"
                type="password"
                autocomplete="new-password"
                placeholder="Repite la contraseña"
                required
            >
        </div>

        <button class="nx-btn nx-btn--primary nx-auth__submit" type="submit">
            Crear cuenta
            <?= nexo_icon('arrow-right') ?>
        </button>
    </form>


    <p class="nx-auth__foot">
        ¿Ya tienes cuenta?
        <a href="<?= url_to('login') ?>">Entra a NEXO</a>
    </p>

    <p class="nx-auth__legal">
        Tus apuntes y archivos son solo tuyos.
    </p>
</div>
<?= $this->endSection() ?>
