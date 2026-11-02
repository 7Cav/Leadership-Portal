<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { PhDownloadSimple, PhUploadSimple } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { postJson } from '@/lib/postJson';

// Import identities: a leader pastes or chooses a .txt or .csv file with one identity per line (trooper, game, ID kind,
// ID), previews what each line would do, then saves the lines that add or change an identity in one go. The file is
// read here into text; only the text is sent, and the server keeps none of it. The server judges every line (scope,
// game, kind, ID shape, owner, Steam) and judges them again on save, so this dialog only shows its answer.
const props = defineProps({
    open: { type: Boolean, default: false },
    // The games, as the page has them, for the example file.
    platforms: { type: Array, required: true },
});
const emit = defineEmits(['update:open']);

const MAX_BYTES = 256 * 1024;
const text = ref('');
const replace = ref(false);
const preview = ref(null);
const error = ref(null);
const previewing = ref(false);
const importing = ref(false);
const fileInput = ref(null);

const STATUS = {
    add: { label: 'Will add', variant: 'default' },
    change: { label: 'Will change', variant: 'default' },
    skip: { label: 'Skipped', variant: 'outline' },
    unchanged: { label: 'Unchanged', variant: 'outline' },
    refused: { label: 'Refused', variant: 'destructive' },
};

const toSave = computed(() => (preview.value?.lines ?? []).filter((line) => line.status === 'add' || line.status === 'change'));
const counts = computed(() => preview.value?.counts ?? {});
const notChecked = computed(() => toSave.value.filter((line) => line.steam === 'not_checked').length);

function setOpen(value) {
    if (importing.value) return;
    emit('update:open', value);
    if (!value) reset();
}

function reset() {
    text.value = '';
    replace.value = false;
    preview.value = null;
    error.value = null;
    if (fileInput.value) fileInput.value.value = '';
}

function changed() {
    preview.value = null;
    error.value = null;
}

async function chooseFile(event) {
    const file = event.target.files?.[0];
    changed();
    if (!file) return;
    if (!/\.(txt|csv)$/i.test(file.name)) {
        error.value = 'Choose a .txt or .csv file.';
        return;
    }
    if (file.size > MAX_BYTES) {
        error.value = 'The file is larger than 256 KB. Split it and import each part.';
        return;
    }
    const content = await file.text();
    if (content.includes('\0')) {
        error.value = 'This is not a text file. Save it as .txt or .csv and try again.';
        return;
    }
    text.value = content;
}

// An example file: the column header, a comment, and one line per active game, the first with its ID kind left out.
function exampleText() {
    const active = props.platforms.filter((p) => !p.retired);
    const lines = ['trooper, game, ID kind, ID', '# One identity per line. Leave the ID kind empty to use the game\'s first kind.'];
    active.forEach((p, index) => {
        const kind = p.idKinds?.[0] ?? '';
        const id = /steam/i.test(kind) ? '76561198012345678' : 'player-id';
        lines.push(index === 0 ? `Novak.E, ${p.label}, , ${id}` : `Novak.E, ${p.label}, ${kind}, ${id}`);
    });
    return `${lines.join('\n')}\n`;
}

function downloadExample() {
    const url = URL.createObjectURL(new Blob([exampleText()], { type: 'text/plain' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = 'game-identities-example.txt';
    link.click();
    URL.revokeObjectURL(url);
}

async function runPreview() {
    previewing.value = true;
    error.value = null;
    try {
        const { ok, data } = await postJson('/game-identities/import/preview', { text: text.value, replace: replace.value });
        if (ok) preview.value = data;
        else error.value = data?.errors?.text?.[0] ?? data?.message ?? 'The preview could not be made. Try again.';
    } catch {
        error.value = 'The preview could not be made. Check your connection and try again.';
    } finally {
        previewing.value = false;
    }
}

function setReplace(value) {
    replace.value = value === true;
    // The preview depends on it, so make it again.
    if (preview.value) runPreview();
}

function runImport() {
    importing.value = true;
    error.value = null;
    router.post(
        '/game-identities/import',
        { text: text.value, replace: replace.value, expected: toSave.value.map((line) => line.line) },
        {
            preserveScroll: true,
            onSuccess: () => {
                importing.value = false;
                setOpen(false);
            },
            onError: (errors) => {
                error.value = errors.text ?? errors.expected ?? 'The identities could not be imported. Preview the file again.';
            },
            onFinish: () => (importing.value = false),
        },
    );
}
</script>

<template>
    <Dialog :open="open" @update:open="setOpen">
        <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-3xl" data-testid="identity-import-dialog">
            <DialogHeader>
                <DialogTitle>Import identities</DialogTitle>
                <DialogDescription>
                    One identity per line: trooper, game, ID kind and ID, separated by commas or tabs. Give the trooper by Cav name (Novak.E) and the game by
                    name or abbreviation. Leave the ID kind empty to use the game's first kind. Blank lines and lines starting with # are ignored. Up to 500
                    lines or 256 KB.
                </DialogDescription>
            </DialogHeader>

            <div class="flex flex-wrap items-center gap-2">
                <Button type="button" variant="outline" size="sm" data-testid="identity-import-choose" @click="fileInput?.click()">
                    <PhUploadSimple aria-hidden="true" />Choose file
                </Button>
                <input ref="fileInput" type="file" accept=".txt,.csv,text/plain,text/csv" class="sr-only" data-testid="identity-import-file" @change="chooseFile" />
                <Button type="button" variant="ghost" size="sm" data-testid="identity-import-example" @click="downloadExample">
                    <PhDownloadSimple aria-hidden="true" />Download example
                </Button>
            </div>

            <Textarea
                v-model="text"
                rows="8"
                class="font-mono text-xs"
                aria-label="Identities to import"
                placeholder="Novak.E, HLL, Steam ID, 76561198012345678"
                data-testid="identity-import-text"
                @input="changed"
            />

            <Label class="flex items-center gap-2 text-[13px] font-normal">
                <Checkbox :model-value="replace" data-testid="identity-import-replace" @update:model-value="setReplace" />
                Replace existing identities
            </Label>

            <p v-if="error" role="alert" class="rounded-md bg-destructive/10 px-3 py-2 text-[13px] text-destructive" data-testid="identity-import-error">{{ error }}</p>

            <div v-if="preview" class="space-y-2" data-testid="identity-import-preview">
                <p class="text-[13px] text-muted-foreground" data-testid="identity-import-counts">
                    {{ counts.add ?? 0 }} to add, {{ counts.change ?? 0 }} to change, {{ counts.unchanged ?? 0 }} unchanged, {{ counts.skip ?? 0 }} skipped,
                    {{ counts.refused ?? 0 }} refused.
                    <span v-if="notChecked" data-testid="identity-import-not-checked">{{ notChecked }} Steam {{ notChecked === 1 ? 'ID was' : 'IDs were' }} not checked with Steam.</span>
                </p>
                <div class="overflow-x-auto rounded-md border border-border">
                    <table class="w-full text-left text-[13px]">
                        <thead class="bg-muted/50 text-xs text-muted-foreground">
                            <tr>
                                <th class="px-2 py-1.5 font-medium">Line</th>
                                <th class="px-2 py-1.5 font-medium">Trooper</th>
                                <th class="px-2 py-1.5 font-medium">Game</th>
                                <th class="px-2 py-1.5 font-medium">ID</th>
                                <th class="px-2 py-1.5 font-medium">Result</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="line in preview.lines" :key="line.line" class="border-t border-border align-top" :data-testid="`identity-import-line-${line.line}`">
                                <td class="px-2 py-1.5 tabular-nums text-muted-foreground">{{ line.line }}</td>
                                <td class="px-2 py-1.5">{{ line.trooper }}</td>
                                <td class="px-2 py-1.5">{{ line.game }}</td>
                                <td class="px-2 py-1.5">
                                    <span class="font-mono text-xs break-all">{{ line.platformId }}</span>
                                    <span v-if="line.idKind" class="block text-xs text-muted-foreground">{{ line.idKind }}</span>
                                    <span v-if="line.status === 'change'" class="block text-xs text-muted-foreground">was {{ line.current }}</span>
                                </td>
                                <td class="px-2 py-1.5">
                                    <Badge :variant="STATUS[line.status]?.variant ?? 'outline'">{{ STATUS[line.status]?.label ?? line.status }}</Badge>
                                    <span v-if="line.reason" class="mt-1 block text-xs text-muted-foreground">{{ line.reason }}</span>
                                    <span v-if="line.steam === 'not_checked'" class="mt-1 block text-xs text-muted-foreground">Not checked with Steam.</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <DialogFooter>
                <Button type="button" variant="outline" :disabled="importing" @click="setOpen(false)">Cancel</Button>
                <Button v-if="!preview" type="button" data-testid="identity-import-preview-button" :disabled="!text.trim() || previewing" @click="runPreview">Preview</Button>
                <Button v-else type="button" data-testid="identity-import-save" :disabled="!toSave.length || importing || previewing" @click="runImport">
                    Import {{ toSave.length }} {{ toSave.length === 1 ? 'identity' : 'identities' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
