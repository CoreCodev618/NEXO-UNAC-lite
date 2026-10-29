<?= $this->extend('layouts/auth') ?>

<?= $this->section('title') ?>Entrar<?= $this->endSection() ?>

<?= $this->section('main') ?>
<?php
$config    = config('Auth');
$errors    = session('errors');
$error     = session('error');
?>
<div class="nx-auth__form-wrap">
    <div class="nx-auth__form-head">
        <span class="nx-auth__eyebrow">Acceso</span>
        <h2 class="nx-auth__title">Qué bueno verte.</h2>
        <p class="nx-auth__desc">Tus apuntes y tu material te están esperando.</p>
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
        action="<?= url_to('login') ?>"
        method="post"
    >
        <?= csrf_field() ?>

        <div class="nx-field">
            <label class="nx-label" for="email">Correo institucional</label>
            <input
                class="nx-input nx-input--lg"
                id="email"
                name="email"
                type="email"
                inputmode="email"
                autocomplete="email"
                placeholder="nombre@<?= esc(\App\Libraries\InstitucionalValidator::domains()[0] ?? '', 'attr') ?>"
                value="<?= esc(old('email') ?? '') ?>"
                required
                autofocus
            >
        </div>

        <div class="nx-field">
            <label class="nx-label" for="password">Contraseña</label>
            <div class="nx-auth__password">
                <input
                    class="nx-input nx-input--lg"
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    placeholder="Tu contraseña"
                    required
                    data-password-input
                >
                <button class="nx-auth__password-toggle" type="button" data-password-toggle aria-label="Mostrar contraseña" aria-pressed="false">
                    <?= nexo_icon('eye') ?>
                </button>
            </div>
            <span class="nx-auth__caps" data-caps-warning>Bloq Mayús está activado.</span>
        </div>

        <div class="nx-auth__row">
            <label class="nx-check">
                <input type="checkbox" name="remember" value="1" <?= old('remember') ? 'checked' : '' ?>>
                <span>Recordarme en este dispositivo</span>
            </label>
        </div>

        <button class="nx-btn nx-btn--primary nx-auth__submit" type="submit">
            Entrar a NEXO
            <?= nexo_icon('arrow-right') ?>
        </button>
    </form>

    <?php if ($config->allowRegistration): ?>
        <div class="nx-divider nx-auth__divider">o</div>
        <a class="nx-btn nx-btn--secondary nx-btn--block" href="<?= url_to('register') ?>">
            Crear una cuenta nueva
            <?= nexo_icon('arrow-right') ?>
        </a>
    <?php endif ?>
</div>
<?= $this->endSection() ?>
