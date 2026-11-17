<script setup>
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    clientName: {
        type: String,
        required: true,
    },
    callbackHost: {
        type: String,
        required: true,
    },
    authToken: {
        type: String,
        required: true,
    },
});

const consentForm = useForm({ auth_token: props.authToken });

function submitConsent() {
    // Passport binds consent to the latest authorization token in the session.
    consentForm.auth_token = props.authToken;
    consentForm.post('/oauth/authorize');
}
</script>

<template>
    <UApp>
        <Head title="Подтвердить доступ GitVerse">
            <meta name="robots" content="noindex, nofollow" />
        </Head>

        <main class="gateway-background flex min-h-dvh items-center justify-center px-4 py-8 text-white sm:px-6">
            <div class="w-full max-w-110">
                <a href="/" class="mx-auto mb-7 flex w-fit items-center gap-3 text-sm font-semibold tracking-tight">
                    <img src="/images/gitverse.svg" alt="" width="36" height="36" class="size-9 rounded-xl bg-slate-800 object-contain p-1.5" />
                    <span>GitVerse <span class="text-slate-400">Gateway</span></span>
                </a>

                <UCard class="rounded-2xl border border-slate-800/80 bg-slate-950/65 shadow-2xl shadow-black/25">
                    <div class="space-y-2">
                        <UBadge color="neutral" variant="subtle" class="rounded-full">Безопасное подключение</UBadge>
                        <h1 class="pt-2 text-2xl font-semibold tracking-tight">
                            Подтвердите доступ
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

                    <UForm :state="consentForm" class="space-y-4" @submit="submitConsent">
                        <p class="text-sm leading-relaxed text-slate-400">
                            Ваш токен уже проверен. Разрешите этому клиенту использовать GitVerse от вашего имени.
                        </p>
                        <UButton type="submit" block size="xl" :loading="consentForm.processing" :disabled="consentForm.processing" class="justify-center rounded-xl">
                            Разрешить доступ
                        </UButton>
                    </UForm>

                    <p class="mt-6 text-xs leading-relaxed text-slate-500">
                        Токен хранится зашифрованным на сервере и не передаётся ChatGPT.
                        Используйте только доверенные клиенты.
                    </p>
                </UCard>
            </div>
        </main>
    </UApp>
</template>
