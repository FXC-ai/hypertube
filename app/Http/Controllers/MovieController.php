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

    private function encodeSubtitle(string $path)
    {
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

        if (count($data_subtitle['streams']) === 0) {
            return [[], ''];
        }


        $subtitles_names = "";
        $subtitles_maps = [];

        (int) $shift = $data_subtitle["streams"][0]["index"];


        $count = 0;
        foreach ($data_subtitle["streams"] as $subtitle) {

            if (
                ($subtitle["tags"]["language"] == "fre" || $subtitle["tags"]["language"] == "eng" || $subtitle["tags"]["language"] == "ger" || $subtitle["tags"]["language"] == "ita")
                && ($subtitle["codec_name"] == "subrip" || $subtitle["codec_name"] == "srt" || $subtitle["codec_name"] == "ass")
            ) {
                $index = (int) $subtitle["index"] - $shift;
                $subtitles_maps[] = "-map";
                $subtitles_maps[] = "0:s:" . $index;
                $subtitles_names = "s:" . $count . ",sgroup:subs,sname:" . $count . "_" . $subtitle["tags"]["language"];
                $count += 1;
                break;
            }
        }

        Log::channel("my_debug")->debug("subtitles_maps = ", [$subtitles_maps]);
        Log::channel("my_debug")->debug("subtitles_names = ", [$subtitles_names]);

        return [$subtitles_maps, $subtitles_names];
    }

    private function encodeAudio(string $path)
    {
        $process_audio = new Process([
            'C:\\ffmpeg\\bin\\ffprobe.exe',
            '-v',
            'error',
            '-select_streams',
            'a',
            '-show_entries',
            'stream=index,codec_name:stream_tags=language,title',
            '-of',
            'json',
            $path,
        ]);

        $process_audio->mustRun();

        $data_audio = json_decode($process_audio->getOutput(), true);

        $nbr_audio = count($data_audio["streams"]);


        $audio_selection = "0:a:0";

        if ($nbr_audio > 1) {
            (int) $shift = $data_audio["streams"][0]["index"];
            foreach ($data_audio["streams"] as $pist) {
                if ($pist["tags"]["language"] == "eng" || $pist["tags"]["language"] == "fre" || $pist["tags"]["language"] == "ita" || $pist["tags"]["language"] == "ger") {
                    $index = (int) $pist['index'] - $shift;
                    $audio_selection =  '0:a:' . $index;
                    break;
                }
            }
        }

        Log::channel("my_debug")->debug("audio selection = ", [$audio_selection]);

        return $audio_selection;
    }

    public function  encode(Movie $movie)
    {
        set_time_limit(0);
        $path = Storage::disk('public')->path("movies/{$movie->id}/{$movie->filename}");

        $temporaryDirectory = storage_path('framework');

        putenv("TMP={$temporaryDirectory}");

        putenv("TEMP={$temporaryDirectory}");

        [$subtitles_maps, $subtitles_names] = $this->encodeSubtitle($path);
        $audio_selection = $this->encodeAudio($path);

        $outputDirectory = Storage::disk('public')->path("movies/{$movie->id}/hls");

        if (! is_dir($outputDirectory)) {
            mkdir($outputDirectory, 0755, true);
        }

        $encodeCommand = [

            'ffmpeg',

            '-y',

            '-i',
            $path,

            '-map',
            '0:v:0',

            '-map',
            $audio_selection,

            ...$subtitles_maps,

            '-c:v',
            'libx264',

            '-preset',
            'veryfast',

            '-crf',
            '23',

            '-pix_fmt',
            'yuv420p',

            '-force_key_frames',
            'expr:gte(t,n_forced*6)',

            '-c:a',
            'aac',

            '-b:a',
            '128k',

            '-ac',
            '2',

            '-c:s',
            'webvtt',

            '-f',
            'hls',

            '-hls_time',
            '6',

            '-hls_playlist_type',
            'event',

            '-hls_list_size',
            '0',

            '-hls_flags',
            'independent_segments+temp_file',

            '-var_stream_map',
            'v:0,a:0,' . $subtitles_names,

            '-master_pl_name',
            'index.m3u8',

            '-master_pl_publish_rate',
            '1',

            '-hls_segment_filename',
            $outputDirectory . DIRECTORY_SEPARATOR . 'stream_%v_segment_%05d.ts',

            $outputDirectory . DIRECTORY_SEPARATOR . 'stream_%v.m3u8',
        ];

        Log::channel('my_debug')->debug('Metadata', [$encodeCommand]);


        $process_encode = new Process($encodeCommand);
        $process_encode->setTimeout(null);
        $process_encode->mustRun();

        // Log::channel('my_debug')->debug('Metadata', ['output' => $data_audio["streams"][0]["codec_name"]]);
        // Log::channel('my_debug')->debug('Metadata', ['output' => $data_audio["streams"][0]["tags"]["language"]]);
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
