<script setup>
import { Head } from '@inertiajs/vue3';

// `error` is flashed by a failed/cancelled callback (AC #5); null on a clean visit.
defineProps({
    error: { type: String, default: null },
});
</script>

<template>
    <Head title="Sign in · 7Cav Leadership Portal" />

    <div class="login-page">
        <div class="split">
            <section class="hero">
                <div class="watermark" aria-hidden="true">
                    <img src="/assets/cav-shield.png" alt="" />
                </div>

                <div class="brand-mark">
                    <span class="shield"><img src="/assets/cav-shield.png" alt="1st Cavalry Division" /></span>
                    <div class="name">7TH CAVALRY REGIMENT <span>Leadership Portal</span></div>
                </div>

                <div class="pitch">
                    <div class="eyebrow">Command tools</div>
                    <h1>Run your platoon, <em>not your spreadsheet.</em></h1>
                    <p>One portal for section overviews, weekly rollcalls, promotion proposals, medal write-ups and counseling — all wired straight into the 7cav.us forum.</p>
                    <ul class="feature-list">
                        <li><span class="dot">RC</span>Weekly rollcall, one click to forum</li>
                        <li><span class="dot">SP</span>Live SP attendance from servers</li>
                        <li><span class="dot">P</span>Promotion eligibility, auto-detected</li>
                        <li><span class="dot">M</span>Medal &amp; transfer post utilities</li>
                    </ul>
                </div>

                <div class="footer-row">
                    <span>© 7th Cavalry Gaming Regiment</span>
                    <span>Restricted access · Leadership only</span>
                </div>
            </section>

            <aside class="login-side">
                <div class="login-inner">
                    <div class="shield-small"><img src="/assets/cav-shield.png" alt="" /></div>
                    <h2>Sign in</h2>
                    <div class="lead">Use your 7cav.us forum account to continue.</div>

                    <div v-if="error" class="error" role="alert">{{ error }}</div>

                    <!-- Full-page navigation (not an Inertia visit): it leaves the app for 7cav.us. -->
                    <a class="sso-btn" href="/auth/redirect">
                        <span class="ico-shield"><img src="/assets/cav-shield.png" alt="" /></span>
                        Continue with 7Cav
                    </a>

                    <a class="secondary-btn" href="https://7cav.us/help/">Trouble signing in?</a>

                    <div class="notice">
                        You'll be redirected to <strong class="notice-em">7cav.us</strong> to authorize. We only read your forum identity — never your password.
                    </div>

                    <div class="help-row">
                        New to the regiment? Visit <a href="https://7cav.us/recruitment/">recruitment</a> first.<br />
                        Trouble? Ping S6 in <a href="https://7cav.us/">#tech-support</a> on Discord.
                    </div>
                </div>
            </aside>
        </div>
    </div>
</template>

<style scoped>
/*
 | Ported verbatim from the pixel contract:
 | _bmad-output/planning-artifacts/mockups/login.html + assets/styles.css :root.
 | Scoped to the page so the dark+gold login look is self-contained.
 */
.login-page {
    --bg: #1a1d20;
    --panel: #23272b;
    --panel-2: #2c3137;
    --border: #353a40;
    --text: #e6e8ea;
    --muted: #8a9099;
    --muted-2: #5f656d;
    --gold: #f0b929;
    --gold-soft: #b88e1f;
    --gold-bg: rgba(240, 185, 41, 0.1);
    --bad: #d9534f;
    --bad-bg: rgba(217, 83, 79, 0.12);
    --font: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;

    min-height: 100vh;
    background: var(--bg);
    color: var(--text);
    font-family: var(--font);
}

.split {
    display: grid;
    grid-template-columns: 1.1fr 1fr;
    min-height: 100vh;
}
@media (max-width: 880px) {
    .split {
        grid-template-columns: 1fr;
    }
}

/* Left hero */
.hero {
    position: relative;
    background:
        radial-gradient(circle at 18% 25%, rgba(240, 185, 41, 0.1), transparent 55%),
        radial-gradient(circle at 80% 80%, rgba(180, 40, 30, 0.18), transparent 50%),
        linear-gradient(180deg, #15191c 0%, #0d1013 100%);
    padding: 48px 56px 40px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    overflow: hidden;
}
.hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background-image:
        linear-gradient(rgba(240, 185, 41, 0.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(240, 185, 41, 0.04) 1px, transparent 1px);
    background-size: 56px 56px;
    -webkit-mask-image: radial-gradient(ellipse at top left, black, transparent 75%);
    mask-image: radial-gradient(ellipse at top left, black, transparent 75%);
    pointer-events: none;
}
.hero .watermark {
    position: absolute;
    right: -120px;
    bottom: -120px;
    width: 520px;
    height: 520px;
    opacity: 0.05;
    pointer-events: none;
    filter: drop-shadow(0 0 40px rgba(240, 185, 41, 0.3));
}
.hero .watermark img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}
.hero .brand-mark {
    display: flex;
    align-items: center;
    gap: 14px;
    position: relative;
}
.hero .brand-mark .shield {
    width: 48px;
    height: 48px;
    filter: drop-shadow(0 2px 6px rgba(0, 0, 0, 0.5));
}
.hero .brand-mark .shield img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    display: block;
}
.hero .brand-mark .name {
    font-weight: 700;
    font-size: 15px;
    letter-spacing: 0.5px;
}
.hero .brand-mark .name span {
    display: block;
    color: var(--muted);
    font-size: 12px;
    font-weight: 400;
    letter-spacing: 0.3px;
    margin-top: 2px;
}
.hero .pitch {
    position: relative;
    max-width: 540px;
}
.hero .pitch .eyebrow {
    color: var(--gold);
    text-transform: uppercase;
    letter-spacing: 2px;
    font-size: 11px;
    font-weight: 700;
    margin-bottom: 18px;
}
.hero .pitch h1 {
    font-size: 40px;
    line-height: 1.12;
    margin: 0 0 18px;
    font-weight: 700;
    letter-spacing: -0.4px;
}
.hero .pitch h1 em {
    font-style: normal;
    color: var(--gold);
}
.hero .pitch p {
    color: var(--muted);
    font-size: 14.5px;
    line-height: 1.65;
    margin: 0 0 28px;
}
.hero .feature-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}
.hero .feature-list li {
    display: flex;
    gap: 10px;
    color: var(--text);
    font-size: 13px;
    line-height: 1.45;
}
.hero .feature-list li .dot {
    flex: 0 0 26px;
    width: 26px;
    height: 26px;
    border-radius: 6px;
    background: var(--gold-bg);
    color: var(--gold);
    display: grid;
    place-items: center;
    font-weight: 800;
    font-size: 11px;
    border: 1px solid var(--gold-soft);
}
.hero .footer-row {
    display: flex;
    gap: 16px;
    color: var(--muted-2);
    font-size: 11px;
    letter-spacing: 0.4px;
    position: relative;
}

/* Right login panel */
.login-side {
    background: var(--bg);
    padding: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.login-inner {
    width: 100%;
    max-width: 380px;
}
.login-inner .shield-small {
    display: none;
    width: 64px;
    height: 64px;
    margin: 0 auto 14px;
}
@media (max-width: 880px) {
    .login-inner .shield-small {
        display: block;
    }
}
.login-inner .shield-small img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}
.login-inner h2 {
    margin: 0 0 6px;
    font-size: 24px;
    font-weight: 600;
}
.login-inner .lead {
    color: var(--muted);
    font-size: 13.5px;
    margin-bottom: 28px;
}
.error {
    margin-bottom: 18px;
    padding: 12px 14px;
    background: var(--bad-bg);
    border: 1px solid var(--bad);
    border-radius: 6px;
    color: var(--bad);
    font-size: 13px;
    line-height: 1.5;
}
.sso-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    width: 100%;
    padding: 14px 18px;
    background: var(--gold);
    color: #1a1300;
    border: none;
    border-radius: 8px;
    font-size: 15px;
    font-weight: 700;
    font-family: var(--font);
    cursor: pointer;
    text-decoration: none;
    transition:
        transform 0.12s ease,
        box-shadow 0.15s ease,
        background 0.12s ease;
    box-shadow: 0 4px 16px rgba(240, 185, 41, 0.25);
}
.sso-btn:hover {
    background: #ffc833;
    transform: translateY(-1px);
    box-shadow: 0 8px 22px rgba(240, 185, 41, 0.35);
}
.sso-btn:active {
    transform: translateY(0);
}
.sso-btn .ico-shield {
    width: 24px;
    height: 24px;
    filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.4));
}
.sso-btn .ico-shield img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    display: block;
}
.secondary-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 11px 14px;
    margin-top: 10px;
    background: transparent;
    color: var(--muted);
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 13px;
    font-family: var(--font);
    cursor: pointer;
    text-decoration: none;
}
.secondary-btn:hover {
    color: var(--text);
    border-color: var(--muted-2);
}
.notice {
    margin-top: 22px;
    padding: 12px 14px;
    background: var(--panel);
    border: 1px solid var(--border);
    border-left: 3px solid var(--gold);
    border-radius: 6px;
    font-size: 12.5px;
    color: var(--muted);
    line-height: 1.55;
}
.notice-em {
    color: var(--gold);
}
.help-row {
    margin-top: 28px;
    padding-top: 20px;
    border-top: 1px solid var(--border);
    color: var(--muted-2);
    font-size: 12px;
    line-height: 1.6;
}
.help-row a {
    color: var(--muted);
}
.help-row a:hover {
    color: var(--gold);
}
</style>
