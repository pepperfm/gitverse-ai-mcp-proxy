<script setup>
import { Head } from '@inertiajs/vue3';
import { useToast } from '@nuxt/ui/composables';

const pluginUrl = 'https://chatgpt.com/plugins/plugin_asdk_app_6afcdb45a3ac8191840314e21d1f016e?directoryTab=personal';
const mcpUrl = 'https://gitverse-gateway-mcp.pepperfm.com/mcp';
const repositoryUrl = 'https://gitverse.ru/pepper_fm/gitverse-ai-mcp-proxy';
const toast = useToast();

async function copyMcpUrl() {
    try {
        await navigator.clipboard.writeText(mcpUrl);
        toast.add({ title: 'Адрес скопирован', color: 'success', duration: 1500 });
    } catch {
        toast.add({ title: 'Не удалось скопировать адрес', color: 'error', duration: 1500 });
    }
}
</script>

<template>
    <UApp>
        <Head title="GitVerse в ChatGPT">
            <meta name="description" content="GitVerse MCP Gateway: независимый прокси для подключения GitVerse к ChatGPT по персональному токену. Открытый исходный код." />
        </Head>

        <main class="gateway-background min-h-dvh overflow-x-hidden text-white">
            <div class="relative flex min-h-dvh flex-col">
                <div class="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
                    <div class="absolute left-1/2 top-[45%] size-[clamp(320px,54vw,780px)] -translate-x-1/2 -translate-y-1/2 rounded-full border border-sky-400/10" />
                    <div class="absolute left-1/2 top-[45%] size-[clamp(430px,72vw,1040px)] -translate-x-1/2 -translate-y-1/2 rounded-full border border-sky-400/5" />
                </div>

                <header class="relative mx-auto flex w-full max-w-6xl items-center justify-between px-5 py-4 sm:px-8 sm:py-7">
                    <a href="/" class="flex items-center gap-3 text-[15px] font-semibold tracking-tight">
                        <img src="/images/gitverse.svg" alt="" width="36" height="36" class="size-9 rounded-xl bg-slate-800 object-contain p-1.5" />
                        <span>GitVerse <span class="text-slate-400">Gateway</span></span>
                    </a>
                    <a :href="repositoryUrl" target="_blank" rel="noopener noreferrer" class="text-sm text-slate-400 transition hover:text-white">
                        Исходный код <span aria-hidden="true">↗</span>
                    </a>
                </header>

                <section class="relative mx-auto flex w-full max-w-4xl flex-1 flex-col items-center justify-center px-5 pb-4 text-center sm:px-8">
                    <UBadge color="neutral" variant="subtle" class="mb-4 rounded-full px-3 py-1 text-[11px] sm:mb-7">
                        Независимая интеграция с GitVerse
                    </UBadge>

                    <h1 class="max-w-3xl text-[clamp(2.3rem,7.2vw,5.75rem)] leading-[1.06] font-semibold tracking-[-0.06em]">
                        GitVerse.<br />
                        <span class="bg-linear-to-r from-sky-300 via-cyan-300 to-blue-400 bg-clip-text text-transparent">Прямо в ChatGPT.</span>
                    </h1>

                    <p class="mt-5 max-w-xl text-sm leading-relaxed text-slate-400 sm:mt-7 sm:text-lg">
                        Репозитории, задачи, ветки и пул-реквесты без переключения вкладок.
                        Подключите свой аккаунт и работайте с GitVerse в диалоге.
                    </p>

                    <div class="mt-7 flex w-full flex-col justify-center gap-3 sm:mt-9 sm:w-auto sm:flex-row">
                        <UButton :href="pluginUrl" target="_blank" rel="noopener noreferrer" size="xl" class="justify-center rounded-xl px-6 font-semibold">
                            Открыть в ChatGPT <span class="ml-1" aria-hidden="true">↗</span>
                        </UButton>
                        <UButton href="#mcp" color="neutral" variant="outline" size="xl" class="justify-center rounded-xl px-6">
                            Подключить MCP
                        </UButton>
                    </div>
                    <p class="mt-4 text-xs leading-relaxed text-slate-500">
                        Плагин пока личный. Остальные могут подключить MCP по адресу ниже, если их тариф поддерживает собственные подключения.
                    </p>
                </section>

                <footer class="relative px-5 pb-4 text-center text-[11px] text-slate-500 sm:pb-7 sm:text-xs">
                    Персональная авторизация · Ваш токен не передаётся ChatGPT
                </footer>
            </div>

            <section id="mcp" class="border-t border-slate-800/80 bg-slate-950/40 px-5 py-16 sm:px-8 sm:py-24">
                <div class="mx-auto max-w-6xl">
                    <div class="max-w-3xl">
                        <p class="mb-4 font-mono text-xs tracking-widest text-sky-400 uppercase">
                            Под капотом
                        </p>
                        <h2 class="text-3xl font-semibold tracking-tight sm:text-5xl">
                            Это просто прокси.
                        </h2>
                        <p class="mt-5 text-sm leading-relaxed text-slate-400 sm:text-base">
                            У GitVerse уже есть официальный MCP-сервер. Gateway не заменяет его и не переписывает API.
                            Пользователь вводит персональный токен GitVerse (PAT), а Gateway передаёт запросы к существующему MCP от его имени.
                            ChatGPT подключается к самому Gateway через OAuth, не к GitVerse напрямую.
                        </p>
                    </div>

                    <div class="mt-9 overflow-x-auto rounded-2xl border border-slate-800 bg-slate-900/60 px-5 py-6 sm:px-7">
                        <p class="mb-4 font-mono text-[11px] tracking-wide text-slate-500 uppercase">
                            Схема подключения
                        </p>
                        <div class="flex min-w-max items-center gap-3 font-mono text-xs sm:gap-5 sm:text-sm">
                            <span class="text-slate-200">ChatGPT</span>
                            <span class="text-slate-600" aria-hidden="true">→</span>
                            <span class="text-sky-300">Gateway</span>
                            <span class="text-slate-600" aria-hidden="true">→</span>
                            <span class="text-slate-200">GitVerse MCP</span>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-4 md:grid-cols-3">
                        <div class="rounded-2xl border border-slate-800/80 bg-slate-900/35 p-5">
                            <p class="font-mono text-xs text-sky-400">01 / PAT</p>
                            <h3 class="mt-3 font-semibold">Ваш токен GitVerse</h3>
                            <p class="mt-2 text-sm leading-relaxed text-slate-400">
                                Вставьте свой PAT на странице Gateway. ChatGPT получает отдельный доступ к шлюзу, но не сам PAT.
                            </p>
                        </div>
                        <div class="rounded-2xl border border-slate-800/80 bg-slate-900/35 p-5">
                            <p class="font-mono text-xs text-sky-400">02 / MCP</p>
                            <h3 class="mt-3 font-semibold">Проксирование запросов</h3>
                            <p class="mt-2 text-sm leading-relaxed text-slate-400">
                                JSON-RPC-запросы направляются к официальному MCP GitVerse с правами вашего аккаунта.
                            </p>
                        </div>
                        <div class="rounded-2xl border border-slate-800/80 bg-slate-900/35 p-5">
                            <p class="font-mono text-xs text-sky-400">03 / REST</p>
                            <h3 class="mt-3 font-semibold">Одно дополнение</h3>
                            <p class="mt-2 text-sm leading-relaxed text-slate-400">
                                Дополнительный инструмент <code class="break-all text-slate-300">list_user_repositories</code> для чтения списка ваших репозиториев.
                            </p>
                        </div>
                    </div>

                    <div class="mt-6 rounded-2xl border border-slate-800 bg-slate-900/60 p-5 sm:p-7">
                        <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <h3 class="font-semibold">Подключить свой MCP-сервер</h3>
                                <p class="mt-1 text-sm text-slate-400">
                                    Укажите этот адрес при добавлении пользовательского MCP в ChatGPT. На странице Gateway потребуется PAT GitVerse.
                                </p>
                            </div>
                            <UButton color="neutral" variant="outline" size="md" class="shrink-0 justify-center" @click="copyMcpUrl">
                                Копировать URL
                            </UButton>
                        </div>
                        <code class="mt-5 block break-all rounded-xl border border-slate-800 bg-slate-950/70 px-4 py-3 font-mono text-xs text-sky-200 sm:text-sm">{{ mcpUrl }}</code>
                        <p class="mt-4 text-xs leading-relaxed text-slate-500">
                            PAT вводится на Gateway и хранится там в зашифрованном виде. Серверу необходимо доверять:
                            он использует ваш токен для запросов к GitVerse. Исходный код открыт, Gateway можно развернуть самостоятельно.
                            Подключение пользовательских MCP зависит от возможностей вашего аккаунта ChatGPT.
                        </p>
                    </div>

                    <div class="mt-7 flex flex-wrap gap-x-6 gap-y-3 text-sm">
                        <a :href="repositoryUrl" target="_blank" rel="noopener noreferrer" class="text-slate-300 underline decoration-slate-700 underline-offset-4 transition hover:text-white">
                            Исходный код ↗
                        </a>
                        <a href="https://gitverse.ru/docs/ai/mcp/" target="_blank" rel="noopener noreferrer" class="text-slate-400 underline decoration-slate-700 underline-offset-4 transition hover:text-white">
                            Официальный MCP GitVerse ↗
                        </a>
                    </div>
                </div>
            </section>
        </main>
    </UApp>
</template>
