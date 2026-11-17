<script setup>
import { Head } from '@inertiajs/vue3';

const pluginUrl = 'https://chatgpt.com/plugins/plugin_asdk_app_6afcb3637fa88191b018188ee764fc8c?directoryTab=personal';
const repositoryUrl = 'https://gitverse.ru/pepper_fm/gitverse-ai-mcp-proxy';
</script>

<template>
    <UApp>
        <Head title="GitVerse в ChatGPT">
            <meta name="description" content="Работайте с репозиториями, задачами и пул-реквестами GitVerse прямо из ChatGPT." />
        </Head>

        <main class="gateway-background relative flex h-dvh flex-col overflow-hidden text-white">
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
                    <UButton :href="repositoryUrl" target="_blank" rel="noopener noreferrer" color="neutral" variant="outline" size="xl" class="justify-center rounded-xl px-6">
                        Посмотреть код
                    </UButton>
                </div>
            </section>

            <footer class="relative px-5 pb-4 text-center text-[11px] text-slate-500 sm:pb-7 sm:text-xs">
                Персональная авторизация · Ваш токен не передаётся ChatGPT
            </footer>
        </main>
    </UApp>
</template>
