<?php

namespace App\Http\Controllers;

use App\Models\Movie;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Process\Process;


class MovieController extends Controller
{

    /*     public function __construct()
    {
        Log::channel("my_debug")->debug("\n", ["MovieController constructed"]);
    } */


    public function  encode(Movie $movie)
    {

        $path = Storage::disk('public')->path("movies/{$movie->id}/{$movie->filename}");


        $temporaryDirectory = storage_path('framework');

        putenv("TMP={$temporaryDirectory}");
        putenv("TEMP={$temporaryDirectory}");

        Log::channel("my_debug")->debug("TMP, TEMP = ", [getenv('TMP'), getenv('TEMP')]);

        /*   $process_audio = new Process([
            'C:\\ffmpeg\\bin\\ffprobe.exe',
            '-v',
            'error',
            '-select_streams',
            'a',
            '-show_streams',
            '-of',
            'json',
            $path,
        ]);

        $process_audio->mustRun();

        $data_audio = json_decode($process_audio->getOutput(), true);

        Log::channel('my_debug')->debug('Metadata', ['data audio' => $data_audio]);
        Log::channel('my_debug')->debug('Metadata', ['output' => $data_audio["streams"][0]["codec_name"]]);
        Log::channel('my_debug')->debug('Metadata', ['output' => $data_audio["streams"][0]["tags"]["language"]]);
 */
        $process_subtitle = new Process([
            'C:\\ffmpeg\\bin\\ffprobe.exe',
            '-v',
            'error',
            '-select_streams',
            's',
            '-show_entries',
            'stream=index,codec_name:stream_tags=language,title',
            '-of',
            'json',

            $path,
        ]);

        $process_subtitle->mustRun();
        $data_subtitle = json_decode($process_subtitle->getOutput(), true);

        $subtitles_maps = [];
        $subtitles_names = [];

        Log::channel('my_debug')->debug('Metadata', [$data_subtitle["streams"]]);


        foreach ($data_subtitle["streams"] as $subtitle) {
            Log::channel('my_debug')->debug('Metadata', [$subtitle]);
            Log::channel('my_debug')->debug('Metadata', [$subtitle["codec_name"]]);

            if (
                ($subtitle["tags"]["language"] == "fre" || $subtitle["tags"]["language"] == "eng")
                && ($subtitle["codec_name"] == "subrip" || $subtitle["codec_name"] == "srt" || $subtitle["codec_name"] == "ass")
            ) {
                $subtitles_maps[] = "-map 0:s:" . $subtitle["index"];
                $subtitles_names[] = "s:0,sgroup:subs,sname:" . $subtitle["tags"]["language"];
            }
        }

        Log::channel('my_debug')->debug('Metadata', ['data subtitle' => $subtitles_maps]);
        Log::channel('my_debug')->debug('Metadata', ['names subtitle' => $subtitles_names]);
    }


    public function hlsManifest(Movie $movie): BinaryFileResponse
    {

        Log::channel("my_debug")->debug("\n", ["hlsManifest :"]);

        $path = Storage::disk('public')->path("movies/{$movie->id}/hls/index.m3u8");

        Log::channel("my_debug")->debug("\n", ['path = ', $path]);


        return response()->file($path, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Cache-Control' => 'private, no-cache, must-revalidate', // Le manifeste utilise no-cache afin que le lecteur puisse le redemander. C’est particulièrement utile plus tard lorsque le manifeste sera produit progressivement.
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function hlsSegment(Movie $movie, string $segment,): BinaryFileResponse
    {

        Log::channel("my_debug")->debug("hlsSegment ", ["called"]);

        $path = Storage::disk('public')->path("movies/{$movie->id}/hls/{$segment}");

        Log::channel("my_debug")->debug("hls segment = ", [$path]);

        return response()->file($path, [
            'Content-Type' => 'video/mp2t',
            'Cache-Control' => 'private, max-age=31536000, immutable', // Les segments sont immuables : segment_00001.ts ne doit jamais changer après sa création. Ils peuvent donc être conservés longtemps dans le cache privé du navigateur.
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }


    public function watch(Movie $movie): BinaryFileResponse
    {
        $filename = Storage::disk('public')->path($movie->filename);

        $path = Storage::disk('public')->path("movies/{$movie->id}/{$movie->filename}");

        return response()->file($path, [
            'Content-Type' => 'video/mp4',
            'Accept-Ranges' => 'bytes',
        ]);
    }
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Movie $movie): Response
    {
        return Inertia::render(
            'movies/show',
            [
                "movie" => $movie,
            ]
        );
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Movie $movie)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Movie $movie)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Movie $movie)
    {
        //
    }
}
