<?php

declare(strict_types=1);

namespace TriCoreDb\Exception;

use TriCoreDb\ErrorCode;
use TriCoreDb\Response;

/**
 * The server answered a request with a status other than `ok`.
 *
 * The connection is still healthy: the refusal frame arrived whole.
 */
final class ServerException extends TriCoreException
{
    private Response $response;

    /**
     * @param string $message Human-readable description.
     * @param Response $response The refused response.
     */
    public function __construct(string $message, Response $response)
    {
        parent::__construct($message, $response->getErrorCode(), $response->getLeaderHint());
        $this->response = $response;
    }

    /**
     * Build the exception for a response whose status is not `ok`.
     *
     * @param Response $response The refused response.
     * @param bool $inTransaction Whether a session transaction was open on the connection.
     */
    public static function fromResponse(Response $response, bool $inTransaction): self
    {
        $message = sprintf(
            '%s (server status: %s)',
            $response->message() ?? 'request failed',
            $response->status
        );
        if ($response->getErrorCode() === ErrorCode::NOT_LEADER) {
            $hint = $response->getLeaderHint();
            $message .= $hint !== null
                ? sprintf(
                    ' [not_leader: the leader serves clients at `%s`. This driver does not follow the hint '
                    . 'on its own; connect there and send the request again.',
                    $hint
                )
                : ' [not_leader: there is no leader address to name (an election is in progress, or the '
                . 'node has no leader address configured); wait and try again.';
            if ($inTransaction) {
                $message .= ' The open session transaction is over: it is bound to this connection.';
            }
            $message .= ']';
        }

        return new self($message, $response);
    }

    /** The full response the server refused with. */
    public function getResponse(): Response
    {
        return $this->response;
    }

    /** The response status: `error` or `not_implemented`. */
    public function getStatus(): string
    {
        return $this->response->status;
    }
}
