<?php

namespace App\Integration\Forum\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasFormBody;

/**
 * Starts a forum conversation, the forum's PM (#565): `POST {base}/api/conversations/` (XenForo 2.3), form-encoded,
 * sent with the user's own OAuth token (`conversation:write`, applied by ForumApi), so it comes from their account.
 */
class StartConversationRequest extends Request implements HasBody
{
    use HasFormBody;

    protected Method $method = Method::POST;

    /**
     * @param  list<int>  $recipientIds  forum user ids
     */
    public function __construct(
        private readonly array $recipientIds,
        private readonly string $title,
        private readonly string $message,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/conversations/';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'recipient_ids' => array_values($this->recipientIds),
            'title' => $this->title,
            'message' => $this->message,
        ];
    }
}
