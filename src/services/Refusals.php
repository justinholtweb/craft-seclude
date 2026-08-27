<?php

declare(strict_types=1);

namespace justinholtweb\seclude\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use justinholtweb\seclude\models\Verdict;
use justinholtweb\seclude\Plugin;
use Throwable;

/**
 * An optional record of what Seclude refused.
 *
 * Off by default, and it should stay off on most sites: the refusals worth knowing about are the
 * ones somebody complains about, and a busy index request generates hundreds that nobody will ever
 * read. It earns its place while a policy is being rolled out, when the question "what did this
 * actually stop?" has a real answer.
 */
class Refusals extends Component
{
    private const TABLE = '{{%seclude_refusals}}';

    /** @var array<string, bool> */
    private array $_seen = [];

    public function record(ElementInterface $element, ?User $user, string $ability, Verdict $verdict): void
    {
        $key = sprintf('%d:%d:%s', (int)($user?->id ?? 0), (int)($element->id ?? 0), $ability);

        // One row per element per ability per request. The CP asks the same question repeatedly
        // while working out which buttons to draw, and a log that counts those is a log of its own
        // implementation details.
        if (isset($this->_seen[$key])) {
            return;
        }

        $this->_seen[$key] = true;

        try {
            $now = Db::prepareDateForDb(new \DateTime());

            Craft::$app->getDb()->createCommand()->insert(self::TABLE, [
                'policyId' => $verdict->policy?->id,
                'userId' => $user?->id,
                'elementId' => $element->id,
                'ability' => $ability,
                'reason' => mb_substr($verdict->reason, 0, 255),
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        } catch (Throwable $e) {
            // Logging a refusal must never turn into refusing the request, or into a 500 on an
            // element index. The decision has already been made and stands either way.
            Craft::warning('Could not record refusal: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
        }
    }

    public function recent(int $limit = 100): array
    {
        return (new Query())
            ->select(['id', 'policyId', 'userId', 'elementId', 'ability', 'reason', 'dateCreated'])
            ->from([self::TABLE])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    public function clear(): int
    {
        return (int)Craft::$app->getDb()->createCommand()->delete(self::TABLE)->execute();
    }

    public function prune(): int
    {
        $days = Plugin::getInstance()->getSettings()->logRetentionDays;
        $cutoff = (new \DateTime())->modify("-{$days} days");

        return (int)Craft::$app->getDb()->createCommand()->delete(self::TABLE, [
            '<', 'dateCreated', Db::prepareDateForDb($cutoff),
        ])->execute();
    }
}
