<style>
/* Navegación contextual del expediente - Fase 19A */
.exp-workspace {
    display: grid;
    grid-template-columns: 286px minmax(0, 1fr);
    gap: 1.1rem;
    align-items: start;
    margin-top: .35rem;
}

.exp-case-nav {
    position: sticky;
    top: 1rem;
    background: #ffffff;
    border: 1px solid #dbe3ef;
    border-radius: 20px;
    box-shadow: 0 12px 28px rgba(15,23,42,.06);
    padding: 1rem;
}

.exp-case-content {
    min-width: 0;
}

.exp-case-nav-summary {
    background:
        radial-gradient(circle at 90% 10%, rgba(37,99,235,.14), transparent 28%),
        linear-gradient(135deg, #f8fafc 0%, #eff6ff 100%);
    border: 1px solid #bfdbfe;
    border-radius: 18px;
    padding: .95rem;
    margin-bottom: .85rem;
}

.exp-case-nav-kicker {
    color: #2563eb;
    font-size: .68rem;
    font-weight: 800;
    letter-spacing: .11em;
    text-transform: uppercase;
    margin-bottom: .28rem;
}

.exp-case-nav-number {
    color: #0f172a;
    font-size: 1.03rem;
    font-weight: 800;
    line-height: 1.25;
    word-break: break-word;
}

.exp-case-nav-meta {
    display: grid;
    gap: .32rem;
    margin-top: .72rem;
    color: #475569;
    font-size: .75rem;
    font-weight: 700;
}

.exp-case-nav-meta span {
    display: flex;
    align-items: center;
    gap: .38rem;
    min-width: 0;
}

.exp-case-nav-meta i {
    color: #2563eb;
    font-size: .62rem;
}

.exp-case-nav-current {
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    border-radius: 14px;
    padding: .7rem .78rem;
    margin-bottom: .9rem;
}

.exp-case-nav-current span {
    display: block;
    color: #64748b;
    font-size: .66rem;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
    margin-bottom: .22rem;
}

.exp-case-nav-current strong {
    display: block;
    color: #0f172a;
    font-size: .86rem;
    font-weight: 800;
}

.exp-case-nav-group {
    padding-top: .85rem;
    margin-top: .85rem;
    border-top: 1px solid #e2e8f0;
}

.exp-case-nav-group:first-of-type {
    border-top: 0;
    margin-top: 0;
    padding-top: 0;
}

.exp-case-nav-title {
    color: #64748b;
    font-size: .66rem;
    font-weight: 800;
    letter-spacing: .1em;
    text-transform: uppercase;
    margin: 0 0 .48rem .2rem;
}

.exp-case-nav-link {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .55rem;
    color: #1e3a8a;
    text-decoration: none;
    border: 1px solid transparent;
    border-radius: 13px;
    padding: .68rem .72rem;
    font-size: .84rem;
    font-weight: 800;
    margin-bottom: .28rem;
    transition: background .15s ease, border-color .15s ease, color .15s ease, transform .15s ease;
}

.exp-case-nav-link:hover {
    background: #eff6ff;
    border-color: #bfdbfe;
    color: #1d4ed8;
    transform: translateX(1px);
}

.exp-case-nav-link.active {
    background: #2563eb;
    border-color: #2563eb;
    color: #ffffff;
    box-shadow: 0 10px 22px rgba(37,99,235,.18);
}

.exp-case-nav-link.active .exp-tab-badge {
    background: rgba(255,255,255,.16);
    border-color: rgba(255,255,255,.32);
    color: #ffffff;
}

.exp-case-nav-left {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    min-width: 0;
}

.exp-case-nav-left i {
    font-size: .95rem;
    flex: 0 0 auto;
}

.exp-case-nav-left span {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
}

.exp-mobile-nav {
    display: none;
    background: #ffffff;
    border: 1px solid #dbe3ef;
    border-radius: 16px;
    box-shadow: 0 10px 24px rgba(15,23,42,.05);
    padding: .85rem;
    margin-bottom: 1rem;
}

.exp-mobile-nav label {
    display: flex;
    align-items: center;
    gap: .4rem;
    color: #2563eb;
    font-size: .72rem;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
    margin-bottom: .45rem;
}

.exp-mobile-nav select {
    width: 100%;
    border: 1px solid #bfdbfe;
    border-radius: 12px;
    padding: .68rem .78rem;
    color: #0f172a;
    font-size: .9rem;
    font-weight: 700;
    background: #f8fafc;
}

@media (max-width: 1180px) {
    .exp-workspace {
        grid-template-columns: 246px minmax(0, 1fr);
    }

    .exp-case-nav {
        padding: .85rem;
    }

    .exp-case-nav-link {
        font-size: .8rem;
        padding: .62rem .66rem;
    }
}

@media (max-width: 920px) {
    .exp-workspace {
        display: block;
    }

    .exp-case-nav {
        display: none;
    }

    .exp-mobile-nav {
        display: block;
    }
}
</style>
