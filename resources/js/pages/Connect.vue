<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    mode: {
        type: String,
        required: true,
    },
    clientName: {
        type: String,
        required: true,
    },
    callbackHost: {
        type: String,
        required: true,
    },
    csrfToken: {
        type: String,
        required: true,
    },
    authToken: {
        type: String,
        default: null,
    },
});

const page = usePage();
const personalToken = ref('');
const showToken = ref(false);
const tokenError = computed(() => page.props.errors?.gitverse_token ?? null);
</script>

<template>
    <UApp>
        <Head title="Подключить GitVerse">
            <meta name="robots" content="noindex, nofollow" />
        </Head>

        <main class="gateway-background flex min-h-dvh items-center justify-center px-4 py-8 text-white sm:px-6">
            <div class="w-full max-w-[440px]">
                <a href="/" class="mx-auto mb-7 flex w-fit items-center gap-3 text-sm font-semibold tracking-tight">
                    <img src="/images/gitverse.png" alt="" width="36" height="36" class="size-9 rounded-xl bg-slate-800 object-contain p-1.5" />
                    <span>GitVerse <span class="text-slate-400">Gateway</span></span>
                </a>

                <UCard class="rounded-2xl border border-slate-800/80 bg-slate-950/65 shadow-2xl shadow-black/25">
                    <div class="space-y-2">
                        <UBadge color="neutral" variant="subtle" class="rounded-full">Безопасное подключение</UBadge>
                        <h1 class="pt-2 text-2xl font-semibold tracking-tight">
                            {{ mode === 'consent' ? 'Подтвердите доступ' : 'Подключите GitVerse' }}
                        </h1>
                        <p class="text-sm leading-relaxed text-slate-400">
                            <span class="break-all font-medium text-slate-200">{{ clientName }}</span>
                            запрашивает доступ к вашим репозиториям GitVerse.
                        </p>
                    </div>

                    <div class="my-6 space-y-2 rounded-xl border border-slate-800 bg-slate-900/60 p-3 text-xs leading-relaxed text-slate-400">
                        <div>Адрес возврата: <span class="break-all font-medium text-slate-200">{{ callbackHost }}</span></div>
                        <div>Имя клиента задаётся самим клиентом, а не проверяется GitVerse Gateway.</div>
                    </div>

                    <form v-if="mode === 'consent'" method="post" action="/oauth/authorize" class="space-y-4">
                        <input type="hidden" name="_token" :value="csrfToken" />
                        <input type="hidden" name="auth_token" :value="authToken" />
                        <p class="text-sm leading-relaxed text-slate-400">
                            Ваш токен уже проверен. Разрешите этому клиенту использовать GitVerse от вашего имени.
                        </p>
                        <UButton type="submit" block size="xl" class="justify-center rounded-xl">
                            Разрешить доступ
                        </UButton>
                    </form>

                    <form v-else method="post" action="/connect" autocomplete="off" class="space-y-4">
                        <input type="hidden" name="_token" :value="csrfToken" />
                        <UFormField label="Персональный токен GitVerse" name="gitverse_token" :error="tokenError">
                            <UInput
                                v-model="personalToken"
                                name="gitverse_token"
                                :type="showToken ? 'text' : 'password'"
                                size="xl"
                                required
                                autofocus
                                :minlength="12"
                                :maxlength="8192"
                                autocomplete="off"
                                spellcheck="false"
                                placeholder="Вставьте ваш токен"
                                class="w-full"
                            >
                                <template #trailing>
                                    <UButton
                                        type="button"
                                        color="neutral"
                                        variant="link"
                                        size="xs"
                                        :aria-label="showToken ? 'Скрыть токен' : 'Показать токен'"
                                        @click="showToken = !showToken"
                                    >
                                        {{ showToken ? 'Скрыть' : 'Показать' }}
                                    </UButton>
                                </template>
                            </UInput>
                        </UFormField>
                        <UButton type="submit" block size="xl" class="justify-center rounded-xl">
                            Проверить токен
                        </UButton>
                    </form>

                    <p class="mt-6 text-xs leading-relaxed text-slate-500">
                        Токен хранится зашифрованным на сервере и не передаётся ChatGPT.
                        Используйте только доверенные клиенты.
                    </p>
                </UCard>
                <p class="mt-5 text-center text-xs text-slate-500">
                    <a href="https://gitverse.ru/docs/ai/mcp/" target="_blank" rel="noopener noreferrer" class="underline underline-offset-4 hover:text-slate-300">
                        Как получить токен GitVerse ↗
                    </a>
                </p>
            </div>
        </main>
    </UApp>
</template>
