/*
 * ClickFeira - registro do Service Worker e convite de instalação do app.
 * Carregado (com defer) em todas as páginas.
 */
(function () {
    "use strict";

    var script = document.currentScript;

    // Este arquivo fica em /assets/js/, então a raiz do site está dois níveis acima.
    var raiz = script && script.src
        ? new URL("../../", script.src)
        : new URL("./", window.location.href);

    // ---------- Service Worker ----------
    if ("serviceWorker" in navigator) {
        window.addEventListener("load", function () {
            navigator.serviceWorker
                .register(new URL("service-worker.js", raiz).href, { scope: raiz.pathname })
                .catch(function (erro) {
                    console.warn("ClickFeira: não foi possível registrar o service worker.", erro);
                });
        });
    }

    // ---------- Convite de instalação ----------
    var CHAVE = "clickfeira_instalar_dispensado";
    var SETE_DIAS = 7 * 24 * 60 * 60 * 1000;

    function jaInstalado() {
        return window.matchMedia("(display-mode: standalone)").matches ||
            window.navigator.standalone === true;
    }

    function dispensadoRecentemente() {
        try {
            var quando = parseInt(localStorage.getItem(CHAVE), 10);
            return quando && (Date.now() - quando) < SETE_DIAS;
        } catch (e) {
            return false;
        }
    }

    function dispensar() {
        try { localStorage.setItem(CHAVE, String(Date.now())); } catch (e) { /* ignora */ }
    }

    function criarFaixa(texto, textoBotao, aoClicar) {
        var faixa = document.createElement("div");

        faixa.setAttribute("role", "dialog");
        faixa.setAttribute("aria-label", "Instalar o ClickFeira");
        faixa.style.cssText =
            "position:fixed;left:12px;right:12px;bottom:12px;z-index:9999;max-width:460px;margin:0 auto;" +
            "display:flex;align-items:center;gap:12px;padding:13px 14px;border-radius:12px;" +
            "background:#174c32;color:#fff;font:600 13px/1.4 Kanit,Arial,sans-serif;" +
            "box-shadow:0 8px 28px rgba(0,0,0,.28)";

        var span = document.createElement("span");
        span.style.flex = "1";
        span.textContent = texto;
        faixa.appendChild(span);

        if (textoBotao) {
            var botao = document.createElement("button");
            botao.type = "button";
            botao.textContent = textoBotao;
            botao.style.cssText =
                "border:none;border-radius:8px;padding:9px 14px;background:#f2c14e;color:#49370d;" +
                "font:700 13px Kanit,Arial,sans-serif;cursor:pointer;white-space:nowrap";
            botao.addEventListener("click", function () {
                aoClicar(faixa);
            });
            faixa.appendChild(botao);
        }

        var fechar = document.createElement("button");
        fechar.type = "button";
        fechar.setAttribute("aria-label", "Fechar");
        fechar.textContent = "\u00d7";
        fechar.style.cssText =
            "border:none;background:transparent;color:#fff;font-size:22px;line-height:1;cursor:pointer;padding:0 4px";
        fechar.addEventListener("click", function () {
            dispensar();
            faixa.remove();
        });
        faixa.appendChild(fechar);

        document.body.appendChild(faixa);

        return faixa;
    }

    // Android / Chrome / Edge: instalação com um toque.
    var eventoInstalar = null;

    window.addEventListener("beforeinstallprompt", function (evento) {
        evento.preventDefault();

        if (jaInstalado() || dispensadoRecentemente()) {
            return;
        }

        eventoInstalar = evento;

        criarFaixa("Instale o ClickFeira no seu celular para abrir mais rápido.", "Instalar", function (faixa) {
            if (!eventoInstalar) { return; }

            eventoInstalar.prompt();

            eventoInstalar.userChoice.then(function () {
                eventoInstalar = null;
                faixa.remove();
            });
        });
    });

    window.addEventListener("appinstalled", function () {
        eventoInstalar = null;
        dispensar();
    });

    // iPhone / iPad (Safari não tem botão de instalar automático).
    var ehIOS = /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream;
    var ehSafari = /safari/i.test(navigator.userAgent) && !/crios|fxios|edgios/i.test(navigator.userAgent);

    if (ehIOS && ehSafari && !jaInstalado() && !dispensadoRecentemente()) {
        window.addEventListener("load", function () {
            setTimeout(function () {
                criarFaixa(
                    "Para instalar: toque em Compartilhar e depois em \u201cAdicionar \u00e0 Tela de In\u00edcio\u201d."
                );
            }, 2500);
        });
    }
})();
