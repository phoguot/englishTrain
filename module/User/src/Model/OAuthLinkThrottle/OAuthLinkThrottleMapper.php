<?php

declare(strict_types=1);

namespace User\Model\OAuthLinkThrottle;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Adapter\Exception\InvalidQueryException;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Sql;

class OAuthLinkThrottleMapper
{
    public const TABLE_NAME = 'oauth_link_throttle';

    public function __construct(private readonly Adapter $adapter)
    {
    }

    public function recordAttempt(string $ipHash, int $windowSeconds, int $maxAttempts): bool
    {
        $sql = new Sql($this->adapter);
        $now = date('Y-m-d H:i:s');
        try {
            $insert = $sql->insert(OAuthLinkThrottleMapper::TABLE_NAME);
            $insert->values(['ip_hash' => $ipHash, 'window_start' => $now, 'attempts' => 1]);
            $sql->prepareStatementForSqlObject($insert)->execute();

            return true;
        } catch (InvalidQueryException $e) {
            if (!str_contains(strtolower($e->getMessage()), 'duplicate')) {
                throw $e;
            }
        }

        $cutoff = date('Y-m-d H:i:s', time() - $windowSeconds);
        $update = $sql->update(OAuthLinkThrottleMapper::TABLE_NAME);
        $update->set([
            'attempts' => new Expression('IF(window_start <= ?, 1, attempts + 1)', [$cutoff]),
            'window_start' => new Expression('IF(window_start <= ?, ?, window_start)', [$cutoff, $now]),
        ]);
        $update->where(['ip_hash = ?' => $ipHash]);
        $update->where->nest()
            ->lessThanOrEqualTo('window_start', $cutoff)
            ->or
            ->lessThan('attempts', $maxAttempts)
            ->unnest();

        return $sql->prepareStatementForSqlObject($update)->execute()->getAffectedRows() === 1;
    }
}
