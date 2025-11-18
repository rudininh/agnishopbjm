module agnishopbjm

go 1.24.0

require (
	github.com/jackc/pgx/v5 v5.7.6
	github.com/lib/pq v1.10.9

	// SDK TikTok lokal harus punya versi (boleh dummy)
	tiktokshop/open/sdk_golang v1.0.0
)

replace tiktokshop/open/sdk_golang => ./sdkgolang

require (
	github.com/jackc/pgpassfile v1.0.0 // indirect
	github.com/jackc/pgservicefile v0.0.0-20240606120523-5a60cdf6a761 // indirect
	github.com/stretchr/testify v1.11.1 // indirect
	golang.org/x/crypto v0.44.0 // indirect
	golang.org/x/text v0.31.0 // indirect
)
