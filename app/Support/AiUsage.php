<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AiUsageEvent;

/** Records a metered Claude call as an AiUsageEvent, pricing it at the model's current price. */
class AiUsage
{
    public static function record(AiUsageContext $context, string $model, int $inputTokens, int $outputTokens): AiUsageEvent
    {
        $price = AiPricing::for($model);

        return AiUsageEvent::create([
            'user_id' => $context->userId,
            'world_id' => $context->worldId,
            'feature' => $context->feature,
            'detail' => $context->detail,
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_nanos' => AiPricing::costNanos($inputTokens, $outputTokens, $price),
        ]);
    }
}
