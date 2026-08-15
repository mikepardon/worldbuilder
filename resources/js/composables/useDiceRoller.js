import { onMounted, onUnmounted, ref } from 'vue';
import { rollDice } from '@/lib/dice';

/**
 * Shared behaviour for click-to-roll dice. Call `handleClick` from a container's @click; when the
 * click lands on a `.dice-roll[data-roll]` element it rolls and positions a result popover (bind the
 * returned `roll` to <DicePopover>). Clicks elsewhere dismiss it.
 */
export function useDiceRoller() {
    const roll = ref();
    const dismiss = () => { roll.value = undefined; };

    // Returns true when it handled a dice click, so callers can stop their own handling.
    // Shift-click rolls with advantage, Alt-click with disadvantage (roll twice, keep higher/lower).
    const handleClick = (event) => {
        const element = event.target.closest?.('.dice-roll[data-roll]');
        if (!element) return false;
        event.preventDefault();
        const min = Number(element.dataset.min || 0);
        const first = rollDice(element.dataset.roll, min);
        if (!first) return true;

        const mode = event.shiftKey ? 'advantage' : event.altKey ? 'disadvantage' : 'normal';
        let result = { ...first, mode };
        if (mode !== 'normal') {
            const second = rollDice(element.dataset.roll, min);
            const keepFirst = mode === 'advantage' ? first.total >= second.total : first.total <= second.total;
            const chosen = keepFirst ? first : second;
            const dropped = keepFirst ? second : first;
            result = { ...chosen, mode, droppedTotal: dropped.total };
        }

        const rect = element.getBoundingClientRect();
        roll.value = {
            ...result,
            label: element.dataset.label || `Roll ${first.expr}`,
            top: rect.bottom + 6,
            left: rect.left,
        };
        return true;
    };

    const onDocumentClick = (event) => {
        if (event.target.closest?.('.dice-roll') || event.target.closest?.('.dice-popover')) return;
        dismiss();
    };
    onMounted(() => document.addEventListener('click', onDocumentClick));
    onUnmounted(() => document.removeEventListener('click', onDocumentClick));

    return { roll, handleClick, dismiss };
}
