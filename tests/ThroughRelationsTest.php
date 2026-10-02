<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Tests;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use MongoDB\Laravel\Relations\HasManyThrough;
use MongoDB\Laravel\Relations\HasOneThrough;
use MongoDB\Laravel\Tests\Models\Director;
use MongoDB\Laravel\Tests\Models\Film;
use MongoDB\Laravel\Tests\Models\Studio;

class ThroughRelationsTest extends TestCase
{
    public function tearDown(): void
    {
        Studio::truncate();
        Director::truncate();
        Film::truncate();

        parent::tearDown();
    }

    public function testHasManyThrough(): void
    {
        $studio = Studio::create(['name' => 'A24']);
        $aster = $studio->directors()->create(['name' => 'Ari Aster']);
        $aster->films()->create(['title' => 'Hereditary']);
        $aster->films()->create(['title' => 'Midsommar']);
        $studio->directors()->create(['name' => 'Robert Eggers'])->films()->create(['title' => 'The Lighthouse']);

        $other = Studio::create(['name' => 'Blumhouse']);
        $other->directors()->create(['name' => 'Jordan Peele'])->films()->create(['title' => 'Get Out']);

        self::assertSame(
            ['Hereditary', 'Midsommar', 'The Lighthouse'],
            $studio->films->pluck('title')->sort()->values()->all(),
        );
        self::assertSame(['Get Out'], $other->films->pluck('title')->all());
    }

    public function testHasManyThroughWithoutRelatedDocuments(): void
    {
        $studio = Studio::create(['name' => 'A24']);
        $studio->directors()->create(['name' => 'Ari Aster']);

        self::assertInstanceOf(Collection::class, $studio->films);
        self::assertCount(0, $studio->films);
    }

    public function testHasManyThroughWithoutThroughDocuments(): void
    {
        $studio = Studio::create(['name' => 'A24']);
        Director::create(['name' => 'Jordan Peele'])->films()->create(['title' => 'Get Out']);

        self::assertCount(0, $studio->films);
    }

    public function testHasOneThrough(): void
    {
        $studio = Studio::create(['name' => 'A24']);
        $studio->directors()->create(['name' => 'Ari Aster'])->films()->create(['title' => 'Hereditary']);

        self::assertInstanceOf(Film::class, $studio->firstFilm);
        self::assertSame('Hereditary', $studio->firstFilm->title);
    }

    public function testHasOneThroughWithoutRelatedDocuments(): void
    {
        $studio = Studio::create(['name' => 'A24']);
        $studio->directors()->create(['name' => 'Ari Aster']);

        self::assertNull($studio->firstFilm);
    }

    public function testHasManyThroughNarrowedToOne(): void
    {
        $studio = Studio::create(['name' => 'A24']);
        $studio->directors()->create(['name' => 'Ari Aster'])->films()->create(['title' => 'Hereditary']);

        $relation = $studio->films()->one();

        self::assertInstanceOf(HasOneThrough::class, $relation);
        self::assertSame('Hereditary', $relation->getResults()->title);
    }

    public function testConstraintsOnTheRelatedQuery(): void
    {
        $studio = Studio::create(['name' => 'A24']);
        $director = $studio->directors()->create(['name' => 'Ari Aster']);
        $director->films()->create(['title' => 'Hereditary', 'budget' => 10]);
        $director->films()->create(['title' => 'Midsommar', 'budget' => 9]);

        self::assertSame(['Hereditary'], $studio->films()->where('budget', '>', 9)->pluck('title')->all());
        self::assertSame(2, $studio->films()->count());
    }

    public function testSelectingColumnsKeepsTheKeyMatchingTheFarParent(): void
    {
        $this->createStudios();

        $loaded = Studio::with(['films' => static fn (HasManyThrough $relation) => $relation->select('title')])
            ->orderBy('name')
            ->get();

        self::assertSame(
            ['Hereditary', 'Midsommar', 'The Lighthouse'],
            $loaded[0]->films->pluck('title')->sort()->values()->all(),
        );
        self::assertSame(['Get Out'], $loaded[1]->films->pluck('title')->all());
    }

    public function testEagerLoadingHasManyThroughUsesOneQueryPerCollection(): void
    {
        $studios = $this->createStudios();

        $connection = DB::connection('mongodb');
        $connection->enableQueryLog();

        $loaded = Studio::with('films')->orderBy('name')->get();

        self::assertCount(3, $connection->getQueryLog());

        self::assertSame(['A24', 'Blumhouse', 'Neon'], $loaded->pluck('name')->all());
        self::assertSame($studios['a24']->id, $loaded[0]->id);
        self::assertSame(
            ['Hereditary', 'Midsommar', 'The Lighthouse'],
            $loaded[0]->films->pluck('title')->sort()->values()->all(),
        );
        self::assertSame(['Get Out'], $loaded[1]->films->pluck('title')->all());
        self::assertCount(0, $loaded[2]->films);
    }

    public function testEagerLoadingHasOneThroughUsesOneQueryPerCollection(): void
    {
        $this->createStudios();

        $connection = DB::connection('mongodb');
        $connection->enableQueryLog();

        $loaded = Studio::with('firstFilm')->orderBy('name')->get();

        self::assertCount(3, $connection->getQueryLog());

        self::assertInstanceOf(Film::class, $loaded[0]->firstFilm);
        self::assertSame('Get Out', $loaded[1]->firstFilm->title);
        self::assertNull($loaded[2]->firstFilm);
    }

    public function testCustomKeyNames(): void
    {
        $studio = Studio::create(['name' => 'A24', 'cstudio_id' => 'studio-a24']);
        Studio::create(['name' => 'Neon', 'cstudio_id' => 'studio-neon']);

        Director::create(['name' => 'Ari Aster', 'cdirector_id' => 'd-aster', 'cstudio_ref' => 'studio-a24']);
        Director::create(['name' => 'Jordan Peele', 'cdirector_id' => 'd-peele', 'cstudio_ref' => 'studio-neon']);

        Film::create(['title' => 'Hereditary', 'cdirector_ref' => 'd-aster']);
        Film::create(['title' => 'Midsommar', 'cdirector_ref' => 'd-aster']);
        Film::create(['title' => 'Get Out', 'cdirector_ref' => 'd-peele']);

        self::assertSame(
            ['Hereditary', 'Midsommar'],
            $studio->filmsWithCustomKeys->pluck('title')->sort()->values()->all(),
        );
        self::assertSame('Hereditary', $studio->firstFilmWithCustomKeys->title);

        $loaded = Studio::with('filmsWithCustomKeys')->orderBy('name')->get();
        self::assertCount(2, $loaded[0]->filmsWithCustomKeys);
        self::assertSame(['Get Out'], $loaded[1]->filmsWithCustomKeys->pluck('title')->all());
    }

    /** @return array<string, Studio> */
    private function createStudios(): array
    {
        $a24 = Studio::create(['name' => 'A24']);
        $aster = $a24->directors()->create(['name' => 'Ari Aster']);
        $aster->films()->create(['title' => 'Hereditary']);
        $aster->films()->create(['title' => 'Midsommar']);
        $a24->directors()->create(['name' => 'Robert Eggers'])->films()->create(['title' => 'The Lighthouse']);

        $blumhouse = Studio::create(['name' => 'Blumhouse']);
        $blumhouse->directors()->create(['name' => 'Jordan Peele'])->films()->create(['title' => 'Get Out']);

        $neon = Studio::create(['name' => 'Neon']);
        $neon->directors()->create(['name' => 'Bong Joon-ho']);

        return ['a24' => $a24, 'blumhouse' => $blumhouse, 'neon' => $neon];
    }
}
