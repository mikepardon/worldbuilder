<script setup>
defineProps({ roll: { type: Object, default: undefined } });
</script>

<template>
    <Teleport to="body">
        <div v-if="roll" class="dice-popover" :style="{ top: `${roll.top}px`, left: `${roll.left}px` }">
            <!-- Advantage / disadvantage: show both results, the kept one highlighted. -->
            <template v-if="roll.mode && roll.mode !== 'normal'">
                <div class="dice-mode" :class="roll.mode">{{ roll.mode === 'advantage' ? 'Advantage' : 'Disadvantage' }}</div>
                <div class="dice-pair">
                    <span class="pair-kept">{{ roll.total }}</span>
                    <span class="pair-dropped">{{ roll.droppedTotal }}</span>
                </div>
            </template>
            <div v-else class="dice-total">{{ roll.total }}</div>

            <div class="dice-detail">
                <span class="dice-label">{{ roll.label }}</span>
                <span class="dice-rolls">[{{ roll.rolls.join(', ') }}]<template v-if="roll.modifier"> {{ roll.modifier > 0 ? '+' : '−' }}{{ Math.abs(roll.modifier) }}</template></span>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.dice-popover {
    position: fixed;
    z-index: 60;
    min-width: 92px;
    padding: 8px 12px;
    border-radius: 8px;
    border: 1px solid #7a5a1f;
    background: #1c1913;
    box-shadow: 0 10px 28px rgba(0, 0, 0, 0.55);
    font-family: ui-sans-serif, system-ui, sans-serif;
    animation: dice-pop 0.12s ease-out;
    pointer-events: none;
}
.dice-total {
    font-family: 'Cinzel', Georgia, serif;
    font-size: 24px;
    font-weight: 700;
    line-height: 1;
    color: #e9c877;
    text-align: center;
}
.dice-detail {
    margin-top: 4px;
    text-align: center;
    line-height: 1.3;
}
.dice-mode {
    margin-bottom: 3px;
    text-align: center;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}
.dice-mode.advantage { color: #9dc47a; }
.dice-mode.disadvantage { color: #d98a8a; }
.dice-pair {
    display: flex;
    align-items: baseline;
    justify-content: center;
    gap: 10px;
}
.pair-kept {
    font-family: 'Cinzel', Georgia, serif;
    font-size: 24px;
    font-weight: 700;
    line-height: 1;
    color: #e9c877;
}
.pair-dropped {
    font-size: 15px;
    color: #6f7580;
    text-decoration: line-through;
}
.dice-label {
    display: block;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #8a909b;
}
.dice-rolls {
    font-size: 11px;
    color: #c8ccd3;
}
@keyframes dice-pop {
    from { opacity: 0; transform: translateY(-3px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>
