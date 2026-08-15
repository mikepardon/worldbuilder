import { computed, nextTick, reactive } from 'vue';

/**
 * Caret-anchored autocomplete for a plain <textarea>. Detects trigger prefixes ("[[", "[statblock=",
 * "@") as they are typed and surfaces a keyboard-navigable list of suggestions, each of which knows
 * the exact text to insert. Kept editor-agnostic so the article and brew editors can share it.
 *
 * @param {object} options
 * @param {import('vue').Ref<HTMLTextAreaElement|null>} options.textarea
 * @param {{ get: () => string, set: (value: string) => void }} options.content
 * @param {Array<{
 *   prefix: string,
 *   closers?: string[],
 *   guard?: (charBefore: string) => boolean,
 *   items: (query: string) => Array<{ key: string|number, label: string, hint?: string, insert: string }>,
 * }>} options.triggers
 */
export function useTextareaAutocomplete({ textarea, content, triggers }) {
    const state = reactive({ active: false, start: 0, query: '', index: 0, top: 0, left: 0 });
    let activeTrigger = null;

    const items = computed(() => (state.active && activeTrigger ? activeTrigger.items(state.query).slice(0, 8) : []));

    // Pixel position of the caret inside the textarea, measured with a hidden mirror element.
    function caretCoords(el, position) {
        const div = document.createElement('div');
        const computed = window.getComputedStyle(el);
        const properties = [
            'boxSizing', 'width', 'height', 'overflowX', 'overflowY', 'borderTopWidth', 'borderRightWidth',
            'borderBottomWidth', 'borderLeftWidth', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
            'fontStyle', 'fontVariant', 'fontWeight', 'fontStretch', 'fontSize', 'lineHeight', 'fontFamily',
            'textAlign', 'textTransform', 'textIndent', 'letterSpacing', 'wordSpacing', 'tabSize',
        ];
        div.style.position = 'absolute';
        div.style.visibility = 'hidden';
        div.style.whiteSpace = 'pre-wrap';
        div.style.wordWrap = 'break-word';
        properties.forEach((property) => { div.style[property] = computed[property]; });
        div.textContent = el.value.slice(0, position);
        const span = document.createElement('span');
        span.textContent = el.value.slice(position) || '.';
        div.appendChild(span);
        document.body.appendChild(div);
        const coords = {
            top: span.offsetTop + parseInt(computed.borderTopWidth, 10),
            left: span.offsetLeft + parseInt(computed.borderLeftWidth, 10),
            height: parseInt(computed.lineHeight, 10) || parseInt(computed.fontSize, 10),
        };
        document.body.removeChild(div);
        return coords;
    }

    function refresh() {
        const el = textarea.value;
        if (!el) { state.active = false; return; }
        const caret = el.selectionStart;
        const before = content.get().slice(0, caret);

        // Pick the trigger whose prefix opens closest to the caret and is still "open".
        let best = null;
        for (const trigger of triggers) {
            const open = before.lastIndexOf(trigger.prefix);
            if (open === -1) continue;
            const between = before.slice(open + trigger.prefix.length);
            const closers = trigger.closers ?? ['\n'];
            if (closers.some((closer) => between.includes(closer))) continue;
            if (trigger.guard && !trigger.guard(before.charAt(open - 1))) continue;
            if (!best || open > best.open) best = { trigger, open, between };
        }

        if (!best) { state.active = false; return; }
        activeTrigger = best.trigger;
        state.start = best.open;
        state.query = best.between;
        state.index = 0;
        const coords = caretCoords(el, caret);
        state.top = Math.max(4, coords.top - el.scrollTop + coords.height + 4);
        state.left = Math.max(4, Math.min(coords.left - el.scrollLeft, el.clientWidth - 264));
        state.active = true;
    }

    function accept(item) {
        const el = textarea.value;
        const caret = el.selectionStart;
        const text = content.get();
        content.set(text.slice(0, state.start) + item.insert + text.slice(caret));
        state.active = false;
        nextTick(() => {
            el.focus();
            const pos = state.start + item.insert.length;
            el.setSelectionRange(pos, pos);
        });
    }

    const onInput = (event) => { content.set(event.target.value); refresh(); };
    const onKeydown = (event) => {
        if (!state.active || !items.value.length) return;
        if (event.key === 'ArrowDown') { event.preventDefault(); state.index = (state.index + 1) % items.value.length; }
        else if (event.key === 'ArrowUp') { event.preventDefault(); state.index = (state.index - 1 + items.value.length) % items.value.length; }
        else if (event.key === 'Enter' || event.key === 'Tab') { event.preventDefault(); accept(items.value[state.index]); }
        else if (event.key === 'Escape') { event.preventDefault(); state.active = false; }
    };
    const onKeyup = (event) => {
        if (state.active && ['ArrowDown', 'ArrowUp', 'Enter', 'Tab', 'Escape'].includes(event.key)) return;
        if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) refresh();
    };
    const onClick = () => refresh();
    const onBlur = () => { setTimeout(() => { state.active = false; }, 120); };

    return { state, items, accept, onInput, onKeydown, onKeyup, onClick, onBlur };
}
