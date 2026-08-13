<?php

declare(strict_types=1);

use App\Utils\AI\NeuronChatStreamer;
use App\Utils\AI\ChatStreamerInterface;

return [
    ChatStreamerInterface::class => NeuronChatStreamer::class,
];
