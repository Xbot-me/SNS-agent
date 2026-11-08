<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ConnectLayout from '@/layouts/ConnectLayout.vue';
import { store as storeAssistedX } from '@/routes/app/social/x/assisted';

defineProps<{ backUrl: string }>();

const form = useForm({ handle: '' });

const onSubmit = (): void => {
    form.post(storeAssistedX.url());
};
</script>

<template>
    <ConnectLayout
        :title="$t('accounts.x_assisted.title')"
        platform="x"
        :close-url="backUrl"
    >
        <div class="flex flex-col gap-6">
            <div class="flex flex-col gap-2">
                <h1
                    class="text-xl leading-tight font-medium text-foreground"
                    data-testid="connect-title"
                >
                    {{ $t('accounts.x_assisted.title') }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ $t('accounts.x_assisted.description') }}
                </p>
            </div>

            <form class="flex flex-col gap-4" @submit.prevent="onSubmit">
                <div class="grid gap-2">
                    <Label for="handle">{{
                        $t('accounts.x_assisted.handle')
                    }}</Label>
                    <Input
                        id="handle"
                        v-model="form.handle"
                        type="text"
                        autocomplete="off"
                        autofocus
                        :placeholder="
                            $t('accounts.x_assisted.handle_placeholder')
                        "
                        :aria-invalid="Boolean(form.errors.handle)"
                        data-testid="x-assisted-handle"
                    />
                    <InputError :message="form.errors.handle" />
                    <p class="text-sm text-muted-foreground">
                        {{ $t('accounts.x_assisted.hint') }}
                    </p>
                </div>

                <Button
                    type="submit"
                    class="mt-2 w-full"
                    :loading="form.processing"
                    data-testid="x-assisted-submit"
                >
                    {{ $t('accounts.x_assisted.submit') }}
                </Button>
            </form>
        </div>
    </ConnectLayout>
</template>
