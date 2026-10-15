<script>
import { computed, defineComponent, h } from 'vue';
import { parseBbcode } from '@/lib/bbcodeRender';

// A BBCode post drawn as the forum shows it (rollcall review step, Settings > Templates). `lib/bbcodeRender.js` reads the
// BBCode into a tree and this turns the tree into elements with `h()`: every piece of text is a text node and every
// attribute is one the parser checked, so nothing in a template or a trooper's name can become markup. The classes
// live here, not in the parser, so Tailwind finds them.

const CLASSES = {
    b: 'font-semibold',
    i: 'italic',
    u: 'underline underline-offset-2',
    s: 'line-through',
    url: 'text-info underline underline-offset-2 hover:opacity-80',
    quote: 'my-2 rounded-md border border-border bg-muted/50 px-3.5 py-2.5',
    list: 'my-1.5 pl-6',
    li: 'my-0.5',
    table: 'w-full border-collapse text-[13px]',
    th: 'border border-border bg-muted/70 px-3 py-2 text-left align-middle font-semibold',
    td: 'border border-border px-3 py-2 align-middle',
    hr: 'my-3 border-border',
};

const LIST_STYLE = { ul: 'list-disc', 1: 'list-decimal', a: 'list-[lower-alpha]', A: 'list-[upper-alpha]', i: 'list-[lower-roman]', I: 'list-[upper-roman]' };

function draw(nodes, inCell) {
    return nodes.map((node) => {
        if (node.type === 'text') return node.text;
        if (node.type === 'br') return h('br');
        if (node.type === 'quote-by') return h('p', { class: 'mb-1 text-xs font-semibold text-muted-foreground' }, node.text);

        if (node.kind === 'img') {
            // A rank insignia is about 30 by 38 pixels; in a cell it keeps that size, elsewhere an image may be larger.
            const size = inCell ? 'max-h-10' : 'max-h-64';

            return h('img', { ...node.attrs, class: `inline-block max-w-full align-middle ${size}` });
        }

        let extra = '';
        if (node.kind === 'list') extra = LIST_STYLE[node.attrs.type ?? 'ul'] ?? 'list-disc';

        const element = h(
            node.tag,
            { ...node.attrs, style: node.style, class: [CLASSES[node.kind], extra] },
            node.kind === 'hr' ? undefined : draw(node.children, inCell || node.kind === 'td' || node.kind === 'th'),
        );

        // A wide table scrolls inside the post instead of pushing the page sideways.
        return node.kind === 'table' ? h('div', { class: 'my-2 overflow-x-auto' }, [element]) : element;
    });
}

export default defineComponent({
    name: 'BbcodePreview',
    props: {
        source: { type: String, required: true },
    },
    setup(props) {
        const tree = computed(() => parseBbcode(props.source));

        return () => h('div', { class: 'text-sm leading-relaxed break-words text-foreground', 'data-bbcode-preview': '' }, draw(tree.value, false));
    },
});
</script>
