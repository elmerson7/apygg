<?php

namespace App\Logging;

use App\Helpers\Redactor;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\UserDataBag;

/**
 * Callbacks de Sentry (config/sentry.php → before_send / before_breadcrumb): ningún dato
 * personal ni secreto sale hacia Sentry. Usa el mismo Redactor que los logs.
 */
final class SentryScrubber
{
    public static function beforeSend(Event $event, ?EventHint $hint = null): Event
    {
        $request = $event->getRequest();
        if ($request !== []) {
            if (isset($request['cookies'])) {
                $request['cookies'] = Redactor::MASK;
            }
            $event->setRequest(self::arr(Redactor::redact($request)));
        }

        $event->setExtra(self::arr(Redactor::redact($event->getExtra())));
        $event->setTags(self::strings(Redactor::redact($event->getTags())));

        foreach ($event->getContexts() as $name => $data) {
            $event->setContext($name, self::arr(Redactor::redact($data)));
        }

        $message = $event->getMessage();
        if ($message !== null) {
            $event->setMessage(Redactor::redactString($message), self::arr(Redactor::redact($event->getMessageParams())));
        }

        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(Redactor::redactString($exception->getValue()));
        }

        // Usuario: solo el id (sin email, nombre ni IP).
        $user = $event->getUser();
        if ($user !== null) {
            $event->setUser(new UserDataBag($user->getId()));
        }

        $event->setBreadcrumb(array_map(fn (Breadcrumb $b): Breadcrumb => self::scrubBreadcrumb($b), $event->getBreadcrumbs()));

        return $event;
    }

    public static function beforeBreadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        return self::scrubBreadcrumb($breadcrumb);
    }

    private static function scrubBreadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        $result = $breadcrumb;
        $message = $breadcrumb->getMessage();
        if ($message !== null) {
            $result = $result->withMessage(Redactor::redactString($message));
        }
        foreach ($breadcrumb->getMetadata() as $key => $value) {
            $result = $result->withMetadata((string) $key, Redactor::isSensitiveKey((string) $key) ? Redactor::MASK : Redactor::redact($value));
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private static function arr(mixed $value): array
    {
        /** @var array<string, mixed> */
        return is_array($value) ? $value : [];
    }

    /**
     * @return array<string, string>
     */
    private static function strings(mixed $value): array
    {
        /** @var array<string, string> */
        return is_array($value) ? $value : [];
    }
}
