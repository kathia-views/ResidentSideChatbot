@extends('layouts.app')

@section('title', 'Login - LMLinga')

@section('body')
    <div class="lml-register-page lml-login-page">
        <header class="lml-register-header">
            <a href="{{ route('landing') }}" class="logo lml-register-logo text-decoration-none lml-focus-ring rounded-2">
                <img src="{{ asset('assets/images/logo/logo.png') }}" alt="">
                <span>LMLinga</span>
            </a>
        </header>

        <main class="lml-register-main">
            <section class="lml-register-card lml-login-card" aria-labelledby="login-heading">
                <div class="lml-login-card__top">
                    <a
                        href="{{ route('landing') }}"
                        class="lml-register-card__close lml-focus-ring"
                        aria-label="Close login"
                    >
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                    </a>
                </div>

                <div class="lml-login-card__intro">
                    <h1 id="login-heading" class="lml-login-card__heading">Login to Your Account</h1>
                    <p class="lml-login-card__subtitle">
                        Welcome back! Enter your details to log in to your account
                    </p>
                </div>

                <form class="lml-login-form" action="#" method="get" novalidate>
                    <div class="lml-login-field">
                        <label for="full_name" class="lml-login-field__label">
                            <i class="bi bi-person-fill" aria-hidden="true"></i>
                            <span>Full Name</span>
                        </label>
                        <x-lml.text-input
                            name="full_name"
                            id="full_name"
                            autocomplete="name"
                            class="lml-login-field__control w-100"
                        />
                    </div>

                    <div class="lml-login-field">
                        <label for="password" class="lml-login-field__label">
                            <i class="bi bi-lock-fill" aria-hidden="true"></i>
                            <span>Password</span>
                        </label>
                        <x-lml.password-input
                            name="password"
                            id="password"
                            placeholder=""
                            :toggle="false"
                            autocomplete="current-password"
                            class="lml-login-field__control w-100"
                        />
                        <div class="lml-login-forgot">
                            <a href="{{ route('password.request') }}" class="lml-login-forgot__link lml-focus-ring">Forgot Password?</a>
                        </div>
                    </div>

                    <div class="lml-login-actions">
                        <button type="submit" class="lml-register-submit lml-login-submit lml-focus-ring">
                            Login
                        </button>
                    </div>
                </form>
            </section>
        </main>
    </div>
@endsection
