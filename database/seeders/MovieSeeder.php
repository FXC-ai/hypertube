<?php

namespace Database\Seeders;

use App\Enums\ConversionStatus;
use App\Models\Movie;
use Illuminate\Database\Seeder;

class MovieSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create(['filename' => 'test1.mkv']);

        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create(['filename' => 'test2.mkv']);

        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create(['filename' => 'test3.mp4']);

        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create(['filename' => 'test4.mkv']);

        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create(['filename' => 'test5.mkv']);

        // Downloaded through the Client Torrent on "Prepare video" (public domain, archive.org).
        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create([
                'title' => 'Son of Hercules: The Land of Darkness',
                'filename' => 'SonofHerculesTheLandofDarkness.mp4',
                'torrent_url' => 'https://archive.org/download/SonofHerculesTheLandofDarkness/SonofHerculesTheLandofDarkness_archive.torrent',
            ]);



        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create([
                'title' => 'The Wolf Man (1941)',
                'filename' => 'Wolf Man 1941.mp4',
                'torrent_url' => 'https://archive.org/download/wolf-man-1941/wolf-man-1941_archive.torrent',
            ]);

        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create([
                'title' => 'Shadow of a Doubt (1943)',
                'filename' => 'Shadow of a Doubt (1943, USA) Directed by Alfred Hitchcock, Featuring Teresa Wright, Joseph Cotten.mp4',
                'torrent_url' => 'https://archive.org/download/shadowofadoubt1943_202003/shadowofadoubt1943_202003_archive.torrent',
            ]);

        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create([
                'title' => 'Bardelys the Magnificent (1926)',
                'filename' => 'Bardelys The Magnificent (1926).mp4',
                'torrent_url' => 'https://archive.org/download/rec-20231113161848/rec-20231113161848_archive.torrent',
            ]);

        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create([
                'title' => 'Night and the City (1950)',
                'filename' => 'Night and the City (1950, UK) Featuring Richard Widmark, Gene Tierney - Film Noir Full Movie.mp4',
                'torrent_url' => 'https://archive.org/download/nightandthecity1950/nightandthecity1950_archive.torrent',
            ]);

        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create([
                'title' => 'Michael',
                'filename' => 'Michael.mkv',
                'torrent_url' => 'https://archive.org/download/silent-michael/silent-michael_archive.torrent',
            ]);

        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create([
                'title' => 'Penny Serenade (1941)',
                'filename' => 'PennySerenadePresentedByMoviePowder.mp4',
                'torrent_url' => 'https://archive.org/download/PennySerenade/PennySerenade_archive.torrent',
            ]);

        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create([
                'title' => 'McLintock! (1963)',
                'filename' => 'McLintock.mp4',
                'torrent_url' => 'https://archive.org/download/mclintok_widescreen/mclintok_widescreen_archive.torrent',
            ]);

        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create([
                'title' => 'House of Strangers (1949)',
                'filename' => 'House of Strangers [1949] Dir. Joseph L. Mankiewicz, Starring Edward G. Robinson, Richard Conte, Susan Hayward (1)_x264.ia.mp4',
                'torrent_url' => 'https://archive.org/download/house-of-strangers-1949_202006/house-of-strangers-1949_202006_archive.torrent',
            ]);


        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create([
                'title' => 'A Romance of the Redwoods (1917)',
                'filename' => 'Romance of The Redwoods.mp4',
                'torrent_url' => 'https://archive.org/download/RomanceOfTheRedwoods/RomanceOfTheRedwoods_archive.torrent',
            ]);

        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create([
                'title' => 'The Great Flamarion (1945)',
                'filename' => 'Great Flamarion, The.mp4',
                'torrent_url' => 'https://archive.org/download/greatflamarionthe/greatflamarionthe_archive.torrent',
            ]);


        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create([
                'title' => 'Curse of the Demon (1957)',
                'filename' => 'Curse of the Demon.mp4',
                'torrent_url' => 'https://archive.org/download/curse-of-the-demon/curse-of-the-demon_archive.torrent',
            ]);

        Movie::factory()
            ->withConversionStatus(ConversionStatus::Pending)
            ->create([
                'title' => 'Night of the Living Dead (1968)',
                'filename' => 'NightOfTheLivingDead_1080p.mkv',
                'torrent_url' => 'https://archive.org/download/Night.Of.The.Living.Dead_1080p/Night.Of.The.Living.Dead_1080p_archive.torrent',
            ]);

    }
}
