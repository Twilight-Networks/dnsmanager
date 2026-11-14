<?php
/**
 * Datei: tests/ui/records/unit_keys.php
 * Zweck: Öffentliche Testschlüssel für unit.php (dieselben wie in matrix_cases.php; mit openssl erzeugt,
 *        öffentliche Schlüssel sind keine Geheimnisse) und Base64-Grenzfälle.
 */
return [
    'rsa1024' => 'MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQDWjarb4FVCK/CDnuO3pyTdcxTjRYfTy0Fux9ZUBNBbhLi4+Nl7Nl3okhdxXEsXxzN3aJlKL1eCH6zhltoRxGUeBY4kzI4tBs4aDhOvqcijVpkE5QJbf+flaq9r/R6Q/QmXCK2jDpHODIsS7BV9kloKPEVx4A9X/k7mcdnZrICycQIDAQAB',
    'rsa2048' => 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtWYPzKp8o8pmgHw/BbwkXfIAwcbPO5cHPAmRDKTXp00m4OIEhSFFhxEzIuFlQ1Q+D2oTwBvVWSo4O/ftasTumqDy6cJ+NKcUIZiwteGUNVwwtj0LeoyTtYYEjjoPJ6aRZH8K5rYuiG+XQkN0Ct+7FPSThOUIMlw9wT9dXyQ5jagawN74IEeDEx3y4x9js/5TTucp+yyxihlH3g/XHnFraQa24BxkZFa86QieyY4e70mwXlVceAgUSMI1VYnqUq5nowcfosahddzZBs97IQGw/oj2V8oBR10HyoKJt2puc3z7ehRBof8S5dpKrQXZl5MPaIsnHwZssvq3F6hhwhLiGQIDAQAB',
    'rsa4096' => 'MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEA5GICGYdXSXCRFe62Od/mPYem1hVlbRIfRtt78n0TlxF4bGDiYhRSKz3H9vDcxmyUrXktakSfNhyxIDl4oa047nvTTjQhytpr/ZX5J2Ep3+5SHaKLI5eNdRVet9jMgmwwiuwMsLRmUSdMSyTi/DDnRxgoZz7dltIpwoesRyVuEyQ7XIleBtDz+3odInoPeEGu5HMhog/Q6SBBmu+Yj66LwWNc8emAiKRqAPYdKiuk3u2URnZHD93yV0MP/M3iNF/WW8/JpWT2qSaa44bXL9NYLbUpOYvRrsZZF+qd458SdaodJaaBLSm+9MpeFkg0t0T5mpY4EpXLdhp+zUMl3VZ8/Qas7VOoVYiDZy84CnUmNc60JHzERc13492p/dF28hLHvK+IiHbPWAvlHqSJTNtvWRlCFZFMpRaynqh9YvKoYImyYFM/tpP4k8tgnjdaeHkVtUmndtkaC48xUCRQVtFUVtmNhdkofcgWSUuBPrts4BFHNS7utTL/2ri7jY/mGL4rCHrejF0U++uckj3mqX8Xzx8NnVg9XMUyl7zT/0NitO6JSXoKOX3eVJz/uhLShUoFxPYHY2lpLERlwOeUh+h0W6Mk2Wlt316qMixGgsgcD3O5G1/PBZ4z/kc46eQmmH9jglu8eta4ym5exg6Qv3ju5+bMgAYSTVZlLhb5DXj+zUUCAwEAAQ==',
    'ed25519' => 'i512/KfbacAnNRit53WGTABLWfzQr+PgMopN5RIxdHQ=',
    'b252' => str_repeat('AAAA', 63),
    'b256' => str_repeat('AAAA', 64),
];
