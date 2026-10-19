<?php

declare(strict_types=1);

namespace WHMCS\Database {
    use WHMCS\Domain\Domain;

    /** SQLite-backed WHMCS boundary double. Executes the adapter's joins and filters. */
    final class Capsule
    {
        public static \PDO $pdo;

        public static array $locks = [];

        public static function table(string $table): Query
        {
            return new Query($table);
        }

        public static function connection(): self
        {
            return new self;
        }

        public function statement(string $sql): void
        {
            if (str_starts_with($sql, 'CREATE TABLE IF NOT EXISTS mod_vexura_whmcs')) {
                self::$pdo->exec('CREATE TABLE IF NOT EXISTS mod_vexura_whmcs (module TEXT NOT NULL, item_id INTEGER NOT NULL, state TEXT NOT NULL, PRIMARY KEY (module, item_id))');

                return;
            }
            throw new \RuntimeException('Unexpected DDL');
        }

        public function select(string $sql, array $bindings): array
        {
            $key = $bindings[0];
            if (str_contains($sql, 'GET_LOCK')) {
                if (isset(self::$locks[$key])) {
                    return [(object) ['acquired' => 0]];
                }
                self::$locks[$key] = true;

                return [(object) ['acquired' => 1]];
            }
            if (str_contains($sql, 'RELEASE_LOCK')) {
                unset(self::$locks[$key]);

                return [];
            }
            throw new \RuntimeException('Unexpected advisory lock SQL');
        }
    }

    final class Values
    {
        public function __construct(private array $values) {}

        public function all(): array
        {
            return $this->values;
        }
    }

    final class Query
    {
        private array $joins = [];

        private array $where = [];

        private array $bindings = [];

        private string $columns = '*';

        private string $order = '';

        private bool $unique = false;

        public function __construct(private readonly string $table) {}

        public function join(string $table, string $left, string $operator, string $right): self
        {
            $this->joins[] = 'JOIN '.$table.' ON '.$left.' '.$operator.' '.$right;

            return $this;
        }

        public function where(string $column, mixed $operator, mixed $value = null): self
        {
            if (func_num_args() === 2) {
                $value = $operator;
                $operator = '=';
            }
            $this->where[] = $column.' '.$operator.' ?';
            $this->bindings[] = $value;

            return $this;
        }

        public function whereIn(string $column, array $values): self
        {
            $this->where[] = $column.' IN ('.implode(',', array_fill(0, count($values), '?')).')';
            array_push($this->bindings, ...$values);

            return $this;
        }

        public function select(array|string $columns): self
        {
            $this->columns = is_array($columns) ? implode(', ', $columns) : $columns;

            return $this;
        }

        public function orderBy(string $column): self
        {
            $this->order = ' ORDER BY '.$column;

            return $this;
        }

        public function distinct(): self
        {
            $this->unique = true;

            return $this;
        }

        public function get(): array
        {
            $sql = 'SELECT '.($this->unique ? 'DISTINCT ' : '').$this->columns.' FROM '.$this->table.' '.implode(' ', $this->joins).($this->where ? ' WHERE '.implode(' AND ', $this->where) : '').$this->order;
            $statement = Capsule::$pdo->prepare($sql);
            $statement->execute($this->bindings);
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

            return array_map(fn ($row) => $this->table === 'tbldomains' ? new Domain($row) : (object) $row, $rows);
        }

        public function first(): ?object
        {
            return $this->get()[0] ?? null;
        }

        public function exists(): bool
        {
            return $this->first() !== null;
        }

        public function value(string $column): mixed
        {
            return $this->first()->{$column} ?? null;
        }

        public function pluck(string $column): Values
        {
            $this->columns = $column.' AS value';

            return new Values(array_map(static fn ($row) => $row->value, $this->get()));
        }

        public function updateOrInsert(array $key, array $values): void
        {
            if ($this->table !== 'mod_vexura_whmcs') {
                throw new \RuntimeException('Unexpected WHMCS table mutation');
            }
            $row = $key + $values;
            $statement = Capsule::$pdo->prepare('INSERT INTO mod_vexura_whmcs (module,item_id,state) VALUES (?,?,?) ON CONFLICT(module,item_id) DO UPDATE SET state=excluded.state');
            $statement->execute([$row['module'], $row['item_id'], $row['state']]);
        }

        public function chunk(int $size, \Closure $operation): void
        {
            foreach (array_chunk($this->get(), $size) as $rows) {
                $operation($rows);
            }
        }

        public function chunkById(int $size, \Closure $operation): void
        {
            $this->chunk($size, $operation);
        }
    }
}

namespace WHMCS\Domain {
    use WHMCS\Database\Capsule;
    use WHMCS\Database\Query;

    final class Domain
    {
        public function __construct(private readonly array $row) {}

        public function __get(string $key): mixed
        {
            return $this->row[$key] ?? null;
        }

        public static function where(string $column, mixed $value): Query
        {
            return Capsule::table('tbldomains')->where($column, $value);
        }

        public function getRegistrarInterface(): self
        {
            return $this;
        }

        public function call(string $action, array $params): array
        {
            return ('vexuradomains_'.$action)($params + $GLOBALS['moduleParams']);
        }
    }
}

namespace WHMCS\Domains\DomainLookup {
    final class ResultsList extends \ArrayObject {}
    final class SearchResult
    {
        public const STATUS_NOT_REGISTERED = 'available';

        public const STATUS_REGISTERED = 'registered';

        public const STATUS_RESERVED = 'reserved';

        public const STATUS_TLD_NOT_SUPPORTED = 'unsupported';

        public string $status;

        public function __construct(public string $sld, public string $tld) {}

        public function setStatus(string $status): void
        {
            $this->status = $status;
        }
    }
}

namespace {
    use WHMCS\Database\Capsule;

    final class CurlBoundary
    {
        public static array $options = [];

        public static int $status;

        public static Closure $handler;

        public static array $calls = [];

        public static function execute($handle): bool
        {
            $options = self::$options;
            verify($options[CURLOPT_FOLLOWLOCATION] === false && $options[CURLOPT_SSL_VERIFYPEER] === true && $options[CURLOPT_SSL_VERIFYHOST] === 2, 'TLS policy');
            self::$calls[] = $options;
            [$status, $headers, $body] = (self::$handler)($options[CURLOPT_CUSTOMREQUEST], $options[CURLOPT_URL], $options[CURLOPT_HTTPHEADER], $options[CURLOPT_POSTFIELDS] ?? null);
            self::$status = $status;
            $options[CURLOPT_HEADERFUNCTION]($handle, 'HTTP/2 '.$status."\r\n");
            foreach ($headers as $name => $value) {
                $options[CURLOPT_HEADERFUNCTION]($handle, $name.': '.$value."\r\n");
            }

            return strlen($body) === $options[CURLOPT_WRITEFUNCTION]($handle, $body);
        }
    }

    function verify(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
    function encrypt(string $raw): string
    {
        return 'test-encrypted:'.base64_encode($raw);
    }
    function decrypt(string $raw): string
    {
        verify(str_starts_with($raw, 'test-encrypted:'), 'State was not protected');

        return base64_decode(substr($raw, 15), true);
    }
    function add_hook(string $event, int $priority, Closure $callback): void
    {
        $GLOBALS['hooks'][$event][$priority][] = $callback;
    }
    function event(string $name, array $vars = []): void
    {
        $hooks = $GLOBALS['hooks'][$name] ?? [];
        ksort($hooks);
        foreach ($hooks as $callbacks) {
            foreach ($callbacks as $callback) {
                $callback($vars);
            }
        }
    }
    function logActivity(string $message): void
    {
        $GLOBALS['activity'][] = $message;
    }
    function check_token(string $scope): void
    {
        verify($scope === 'WHMCS.default' && ($_POST['token'] ?? '') === 'valid-token', 'CSRF rejected');
    }
    function localAPI(string $action, array $params): array
    {
        verify($params['serviceid'] === 1, 'Wrong service in localAPI');
        $function = $action === 'ModuleCreate' ? 'CreateAccount' : $params['func_name'];
        $result = ($GLOBALS['moduleName'].'_'.$function)($GLOBALS['moduleParams']);
        if ($result === 'success' && $action === 'ModuleCreate') {
            Capsule::$pdo->exec("UPDATE tblhosting SET domainstatus='Active' WHERE id=1");
        }

        return ['result' => $result === 'success' ? 'success' : 'error', 'message' => $result];
    }
}
