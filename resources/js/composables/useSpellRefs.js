import { onMounted, onUnmounted, ref } from 'vue';

/**
 * Click-to-open spell cards. Call `handleClick` from a container's @click; when the click lands on a
 * `.spell-ref[data-spell-id]` it looks the spell up in `spellsRef` (a ref/computed of {id,name,summary,document})
 * and positions a popover (bind the returned `spell` to <SpellPopover>). Clicks elsewhere dismiss it.
 */
export function useSpellRefs(spellsRef) {
    const spell = ref();
    const dismiss = () => { spell.value = undefined; };

    const showFor = (element) => {
        const found = (spellsRef.value ?? []).find((s) => s.id === Number(element.dataset.spellId));
        if (!found) return false;
        const rect = element.getBoundingClientRect();
        // Clamp so the ~380px card doesn't run off the right edge.
        spell.value = { ...found, top: rect.bottom + 6, left: Math.min(rect.left, window.innerWidth - 392) };
        return true;
    };

    // Show on hover of a spell reference; hide when the pointer leaves it.
    const handleOver = (event) => {
        const element = event.target.closest?.('.spell-ref[data-spell-id]');
        if (element) showFor(element);
    };
    const handleOut = (event) => {
        const element = event.target.closest?.('.spell-ref[data-spell-id]');
        if (element && !element.contains(event.relatedTarget)) dismiss();
    };
    // Click also opens it (useful on touch, where there is no hover).
    const handleClick = (event) => {
        const element = event.target.closest?.('.spell-ref[data-spell-id]');
        if (!element) return false;
        event.preventDefault();
        return showFor(element) || true;
    };

    const onDocumentClick = (event) => {
        if (event.target.closest?.('.spell-ref')) return;
        dismiss();
    };
    onMounted(() => document.addEventListener('click', onDocumentClick));
    onUnmounted(() => document.removeEventListener('click', onDocumentClick));

    return { spell, handleClick, handleOver, handleOut, dismiss };
}
