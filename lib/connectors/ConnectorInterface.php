<?php
declare(strict_types=1);

interface ConnectorInterface
{
  public static function code(): string;

  /**
   * @return array{
   *   date:string,
   *   items:array<int, array{external_id:string, amount:float, meta?:mixed}>,
   *   debug?:mixed
   * }
   */
  public function run(array $params): array;
}
