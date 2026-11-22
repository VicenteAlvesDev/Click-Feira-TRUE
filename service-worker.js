/*
 * ClickFeira Assaré - Service Worker
 *
 * Estratégias:
 *  - Páginas (HTML/PHP): sempre da rede. NUNCA são guardadas em cache, porque
 *    têm dados da conta do usuário. Sem internet, mostra offline.html.
 *  - Arquivos do app (css, js, ícones, logo): cache + atualização em segundo plano.
 *  - Fotos enviadas (uploads/): cache primeiro, com limite de quantidade.
 *
 * Ao publicar uma versão nova do app, aumente o número em VERSAO.
 */

const VERSAO = "clickfeira-v4";
const CACHE_ESTATICO = VERSAO + "-estatico";
const CACHE_IMAGENS = VERSAO + "-imagens";
const MAX_IMAGENS = 80;

const BASE = self.registration.scope;

const URL_OFFLINE = new URL("offline.html", BASE).href;

const ARQUIVOS_ESSENCIAIS = [
    "offline.html",
    "manifest.json",
    "assets/img/logo-clickfeira.webp",
    "assets/img/banner-principal.webp",
    "assets/img/app-icon.png",
    "assets/img/app-icon-192.png",
    "assets/img/favicon-32.png",
    "assets/js/pwa.js"
].map((caminho) => new URL(caminho, BASE).href);

// Instalação: guarda a página offline e os arquivos principais.
self.addEventListener("install", (evento) => {
    evento.waitUntil(
        caches.open(CACHE_ESTATICO).then(async (cache) => {
            // A página offline é obrigatória; os demais são "melhor esforço".
            await cache.add(URL_OFFLINE);

            await Promise.all(
                ARQUIVOS_ESSENCIAIS
                    .filter((url) => url !== URL_OFFLINE)
                    .map((url) => cache.add(url).catch(() => null))
            );
        })
    );

    self.skipWaiting();
});

// Ativação: remove caches de versões antigas e liga o preload de navegação.
self.addEventListener("activate", (evento) => {
    evento.waitUntil(
        (async () => {
            const nomes = await caches.keys();

            await Promise.all(
                nomes
                    .filter((nome) => nome.startsWith("clickfeira-") && !nome.startsWith(VERSAO))
                    .map((nome) => caches.delete(nome))
            );

            if (self.registration.navigationPreload) {
                await self.registration.navigationPreload.enable();
            }

            await self.clients.claim();
        })()
    );
});

self.addEventListener("message", (evento) => {
    if (evento.data === "SKIP_WAITING") {
        self.skipWaiting();
    }
});

// Mantém só as imagens mais recentes no cache.
async function limitarCache(nomeCache, maximo) {
    const cache = await caches.open(nomeCache);
    const chaves = await cache.keys();

    if (chaves.length > maximo) {
        await Promise.all(
            chaves.slice(0, chaves.length - maximo).map((chave) => cache.delete(chave))
        );
    }
}

// Cache + atualização em segundo plano (stale-while-revalidate).
async function cacheEAtualiza(requisicao) {
    const cache = await caches.open(CACHE_ESTATICO);
    const guardada = await cache.match(requisicao);

    const rede = fetch(requisicao)
        .then((resposta) => {
            if (resposta && resposta.ok) {
                cache.put(requisicao, resposta.clone());
            }
            return resposta;
        })
        .catch(() => null);

    return guardada || (await rede) || Response.error();
}

// Cache primeiro, rede se não tiver.
async function cachePrimeiro(requisicao) {
    const cache = await caches.open(CACHE_IMAGENS);
    const guardada = await cache.match(requisicao);

    if (guardada) {
        return guardada;
    }

    try {
        const resposta = await fetch(requisicao);

        if (resposta && resposta.ok) {
            await cache.put(requisicao, resposta.clone());
            limitarCache(CACHE_IMAGENS, MAX_IMAGENS);
        }

        return resposta;
    } catch (erro) {
        return Response.error();
    }
}

self.addEventListener("fetch", (evento) => {
    const requisicao = evento.request;

    if (requisicao.method !== "GET") {
        return;
    }

    const url = new URL(requisicao.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    // Nunca interceptar o próprio service worker.
    if (url.pathname.endsWith("/service-worker.js")) {
        return;
    }

    // Páginas: rede primeiro; sem internet, mostra a página offline.
    if (requisicao.mode === "navigate") {
        evento.respondWith(
            (async () => {
                try {
                    const preload = await evento.preloadResponse;

                    if (preload) {
                        return preload;
                    }

                    return await fetch(requisicao);
                } catch (erro) {
                    const cache = await caches.open(CACHE_ESTATICO);
                    const offline = await cache.match(URL_OFFLINE);

                    return offline || new Response(
                        "Você está sem conexão com a internet.",
                        {
                            status: 503,
                            headers: { "Content-Type": "text/plain; charset=utf-8" }
                        }
                    );
                }
            })()
        );

        return;
    }

    // Fotos enviadas por vendedores.
    if (url.pathname.includes("/uploads/")) {
        evento.respondWith(cachePrimeiro(requisicao));
        return;
    }

    // Arquivos do app.
    if (
        url.pathname.includes("/assets/") ||
        url.pathname.endsWith("/manifest.json")
    ) {
        evento.respondWith(cacheEAtualiza(requisicao));
    }
});
