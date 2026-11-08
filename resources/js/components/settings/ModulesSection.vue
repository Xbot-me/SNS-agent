<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

import WorkspaceModuleController from '@/actions/App/Http/Controllers/App/WorkspaceModuleController';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';

const props = defineProps<{
    modules: Record<string, boolean>;
}>();

const values = ref<Record<string, boolean>>({ ...props.modules });
const http = useHttp<Record<string, boolean>>({});
let queue: Promise<void> = Promise.resolve();

/**
 * Each switch saves on its own, one request after another, and a failed save
 * puts the switch back.
 */
const toggle = (module: string, value: boolean): void => {
    const previous = values.value[module];
    values.value = { ...values.value, [module]: value };

    queue = queue.then(async () => {
        try {
            await http
                .transform(() => ({ [module]: value }))
                .patch(WorkspaceModuleController.update.url());
        } catch {
            values.value = { ...values.value, [module]: previous };
            toast.error(trans('modules.save_failed'));
        }
    });
};
</script>

<template>
    <SettingsSection
        :title="$t('modules.heading')"
        :description="$t('modules.description')"
    >
        <div class="flex flex-col gap-6" data-testid="modules-section">
            <div
                v-for="(enabled, module) in values"
                :key="module"
                class="flex items-start justify-between gap-6"
            >
                <div class="flex max-w-[440px] min-w-0 flex-col gap-2">
                    <Label
                        :for="`module-${module}`"
                        class="text-sm leading-tight font-emphasis"
                    >
                        {{ $t(`modules.${module}.label`) }}
                    </Label>
                    <p class="text-sm text-muted-foreground">
                        {{ $t(`modules.${module}.description`) }}
                    </p>
                </div>
                <Switch
                    :id="`module-${module}`"
                    :model-value="enabled"
                    :data-testid="`module-${module}`"
                    class="shrink-0"
                    @update:model-value="toggle(module, Boolean($event))"
                />
            </div>
        </div>
    </SettingsSection>
</template>
