import { Head, InfiniteScroll, useForm, usePoll } from '@inertiajs/react';
import Hls from 'hls.js';
import {
    Film,
    Info,
    LoaderCircle,
    MessageCircle,
    Play,
    RotateCcw,
} from 'lucide-react';
import { useEffect, useRef, useState, useSyncExternalStore } from 'react';

import CommentForm from '@/components/comment-form';
import CommentItem from '@/components/comment-item';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { show } from '@/routes/movies';
import { store as conversionStore } from '@/routes/movies/conversion';
import type { Comment } from '@/types/comment';

type ConversionStatus =
    'pending' | 'queued' | 'converting' | 'playable' | 'converted' | 'failed';

type DownloadStatus =
    | 'pending'
    | 'downloading'
    | 'completed'
    | 'failed'
    | 'unsupported'
    | 'purging';

type ExternalSubtitleStatus =
    'none' | 'pending' | 'queued' | 'processing' | 'ready' | 'failed';

type OmdbData = {
    imdbID: string;
    year: string;
    rated: string | null;
    released: string | null;
    runtime: string;
    genre: string;
    director: string;
    producer: string;
    writer: string;
    actors: string;
    plot: string;
    language: string;
    poster: string | null;
    awards: string;
    imdbRating: number | null;
    imdbVotes: number | null;
    boxOffice: number | null;
} | null;

type ExternalSubtitleTrack = {
    id: number;
    label: string;
    language: string;
    url: string;
};

type MoviePageData = {
    id: number;
    title: string;
    filename: string | null;
    source: string | null;
    download_status: DownloadStatus;
    download_error: string | null;
    conversion_attempt: string | null;
    conversion_status: ConversionStatus;
    conversion_error: string | null;
    can_prepare: boolean;
    playable: boolean;
    preferredlanguage: string;
    movieData: OmdbData;
    external_subtitles_status: ExternalSubtitleStatus;
    external_subtitles: ExternalSubtitleTrack[];
};

type MovieShowProps = {
    moviePageData: MoviePageData;
    comments: { data: Comment[]; meta: { total: number } };
};

const sourceLabels: Record<string, string> = {
    wikidata: 'Wikidata',
    whitelist: 'Whitelist',
    import: 'Imported',
};

const conversionStatusLabel: Record<ConversionStatus, string> = {
    pending: 'Ready to prepare',
    queued: 'Queued',
    converting: 'Preparing video',
    playable: 'Available',
    converted: 'Available',
    failed: 'Preparation failed',
};

/**
 * What this browser can play, probed once when the module loads. The subject forbids any
 * output in the web console, so a browser that cannot play the stream is told on the page
 * rather than logged, and that answer cannot change while the page stays open.
 */
const browserPlayback = {
    mediaSource: Hls.isSupported(),
    nativeHls:
        typeof document === 'undefined'
            ? false
            : document
                  .createElement('video')
                  .canPlayType('application/vnd.apple.mpegurl') !== '',
};

function subscribeToNothing(): () => void {
    return () => {};
}

function HlsPlayer({
    src,
    preferredlanguage,
    externalSubtitles,
}: {
    src: string;
    preferredlanguage: string;
    externalSubtitles: ExternalSubtitleTrack[];
}) {
    const videoRef = useRef<HTMLVideoElement>(null);
    const [failure, setFailure] = useState<string | null>(null);
    // The server render (Inertia SSR, in Node) has neither MediaSource nor a video
    // element: it must not print the warning the browser then removes, which React
    // reports as a hydration error in the console.
    const cannotPlay = useSyncExternalStore(
        subscribeToNothing,
        () => !browserPlayback.mediaSource && !browserPlayback.nativeHls,
        () => false,
    );

    useEffect(() => {
        const languages: { [language: string]: string } = {
            french: 'fr',
            german: 'de',
            english: 'en',
            italian: 'it',
        };

        const video = videoRef.current;

        if (video === null) {
            return;
        }

        if (!browserPlayback.mediaSource && browserPlayback.nativeHls) {
            video.src = src;

            return () => {
                video.removeAttribute('src');
                video.load();
            };
        }

        if (!browserPlayback.mediaSource) {
            // The page says the browser cannot play the stream; nothing is logged.
            return;
        }

        const hls = new Hls({
            autoStartLoad: false,
            subtitlePreference: {
                lang: languages[preferredlanguage],
            },
            lowLatencyMode: false,
            startPosition: 0,
        });

        hls.on(Hls.Events.MEDIA_ATTACHED, () => {
            hls.loadSource(src);
            hls.startLoad(0);
        });

        // hls.js retries on its own after a non-fatal error (a segment not converted yet, a
        // stalled buffer while the torrent downloads): only a fatal one is worth reporting,
        // and the subject forbids anything in the web console, so it is reported on the page.
        hls.on(Hls.Events.ERROR, (_event, data) => {
            if (!data.fatal) {
                return;
            }

            setFailure(
                `The video stream stopped (${data.details}${
                    data.response?.code ? `, HTTP ${data.response.code}` : ''
                }).`,
            );

            if (data.type === Hls.ErrorTypes.MEDIA_ERROR) {
                hls.recoverMediaError();
            }
        });

        hls.on(Hls.Events.SUBTITLE_TRACKS_UPDATED, () => {
            const subtitleIndex = hls.subtitleTracks.findIndex(
                (track) => track.lang === languages[preferredlanguage],
            );

            if (subtitleIndex !== -1) {
                hls.subtitleDisplay = true;
                hls.subtitleTrack = subtitleIndex;
            }
        });

        hls.attachMedia(video);

        return () => {
            hls.destroy();
        };
    }, [preferredlanguage, src]);

    return (
        <>
            <video ref={videoRef} controls className="w-full">
                {externalSubtitles.map((subtitle) => (
                    <track
                        key={subtitle.id}
                        kind="subtitles"
                        src={subtitle.url}
                        srcLang={subtitle.language}
                        label={subtitle.label}
                    />
                ))}
            </video>

            {cannotPlay && (
                <p className="bg-destructive/10 px-4 py-3 text-sm text-destructive">
                    This browser cannot play the video stream.
                </p>
            )}

            {failure !== null && (
                <p className="bg-destructive/10 px-4 py-3 text-sm text-destructive">
                    {failure}
                </p>
            )}
        </>
    );
}

export default function MovieShow({ moviePageData, comments }: MovieShowProps) {
    const conversionForm = useForm({});
    const { stop } = usePoll(2000, {});
    const isUnsupported = moviePageData.download_status === 'unsupported';
    const isPurging = moviePageData.download_status === 'purging';
    const downloadFailed = moviePageData.download_status === 'failed';
    const preparationFailed =
        downloadFailed || moviePageData.conversion_status === 'failed';

    // A click reaches the queue before a worker takes the job: for a moment the server
    // still reports the statuses the movie had when it was clicked. Until they change, the
    // page shows the loading it asked for. Once they changed, only the server says what
    // happens next: a retry that fails again comes back to the statuses it was clicked on,
    // and must show Retry again. A job that fails within a second can do so between two
    // polls, unseen: the page then gives up waiting after 10 s (a worker takes a job in
    // about a second).
    const statuses = `${moviePageData.download_status}:${moviePageData.conversion_status}`;
    const [clickedOn, setClickedOn] = useState<string | null>(null);

    if (clickedOn !== null && clickedOn !== statuses) {
        setClickedOn(null);
    }

    const waitingForWorker = clickedOn === statuses;

    const isPreparing =
        !moviePageData.playable &&
        !isPurging &&
        !isUnsupported &&
        (waitingForWorker ||
            moviePageData.download_status === 'downloading' ||
            ['queued', 'converting'].includes(moviePageData.conversion_status));

    const statusLabel = isPurging
        ? 'Cleaning up stored video'
        : isUnsupported
          ? 'Unsupported torrent'
          : isPreparing
            ? moviePageData.download_status === 'downloading' &&
              moviePageData.conversion_status === 'pending'
                ? 'Downloading'
                : 'Preparing video'
            : downloadFailed
              ? 'Unavailable'
              : conversionStatusLabel[moviePageData.conversion_status];

    // A retry in progress no longer shows the error it is retrying.
    const displayedError =
        isPurging || isPreparing
            ? null
            : isUnsupported || downloadFailed
              ? moviePageData.download_error
              : moviePageData.conversion_error;

    const videoIsPlayable = moviePageData.playable;

    const subtitlesAreTerminal = ['none', 'ready', 'failed'].includes(
        moviePageData.external_subtitles_status,
    );

    useEffect(() => {
        if (
            !isPurging &&
            (isUnsupported || (videoIsPlayable && subtitlesAreTerminal))
        ) {
            stop();
        }
    }, [
        isUnsupported,
        moviePageData.external_subtitles_status,
        stop,
        subtitlesAreTerminal,
        videoIsPlayable,
        isPurging,
    ]);

    const startConversion = (): void => {
        setClickedOn(statuses);
        window.setTimeout(() => setClickedOn(null), 10000);

        conversionForm.post(conversionStore.url(moviePageData.id), {
            preserveScroll: true,
            // The redirect back to this page must not reset the click it remembers.
            preserveState: true,
            onError: () => setClickedOn(null),
        });
    };

    return (
        <>
            <Head title={moviePageData.title} />
            <div className="w-full bg-background">
                <section className="border-b px-4 py-8 sm:px-6 lg:px-8">
                    <div className="mx-auto max-w-4xl">
                        <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">
                            {moviePageData.title}
                        </h1>
                        {moviePageData.source && (
                            <Badge
                                variant="secondary"
                                className="mt-2"
                                title="Legal catalog"
                            >
                                {sourceLabels[moviePageData.source] ??
                                    moviePageData.source}
                            </Badge>
                        )}
                    </div>
                </section>

                <div className="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                    <Card className="overflow-hidden border-border/60 bg-black p-0 shadow-xl">
                        {moviePageData.playable &&
                        moviePageData.conversion_attempt !== null ? (
                            <HlsPlayer
                                src={`/storage/movies/${moviePageData.id}/hls/${moviePageData.conversion_attempt}/index.m3u8`}
                                preferredlanguage={
                                    moviePageData.preferredlanguage
                                }
                                externalSubtitles={
                                    moviePageData.external_subtitles
                                }
                            />
                        ) : (
                            <div className="flex aspect-video flex-col items-center justify-center gap-4 bg-muted px-6 text-center text-muted-foreground">
                                {isPreparing ? (
                                    <LoaderCircle className="size-8 animate-spin" />
                                ) : (
                                    <Film className="size-8" />
                                )}
                                <div>
                                    <p className="font-medium text-foreground">
                                        {isPurging
                                            ? 'This video is currently being cleaned up.'
                                            : isUnsupported
                                              ? 'This torrent has no supported video'
                                              : isPreparing
                                                ? 'The video is being prepared'
                                                : downloadFailed
                                                  ? 'This movie is not available'
                                                  : 'The video is not available yet'}
                                    </p>
                                    <p className="mt-1 text-sm">
                                        {isPurging
                                            ? 'Please try again shortly.'
                                            : isUnsupported
                                              ? 'No MP4 or MKV file was found.'
                                              : isPreparing
                                                ? 'This page will update automatically.'
                                                : downloadFailed
                                                  ? 'The source torrent is missing.'
                                                  : 'Prepare the video to start watching.'}
                                    </p>
                                </div>
                            </div>
                        )}
                    </Card>

                    <Card className="border-border/60 shadow-sm">
                        <CardHeader>
                            <CardTitle className="text-lg">
                                Viewing status
                            </CardTitle>
                            <CardDescription>
                                Track the availability of your video.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex items-center justify-between gap-4 text-sm">
                                <span className="text-muted-foreground">
                                    Status
                                </span>
                                <span className="font-medium">
                                    {statusLabel}
                                </span>
                            </div>
                            <div className="flex items-center justify-between gap-4 text-sm">
                                <span className="text-muted-foreground">
                                    File
                                </span>
                                <span className="max-w-[65%] truncate text-right font-mono text-xs">
                                    {moviePageData.filename}
                                </span>
                            </div>
                            <div className="flex items-center justify-between gap-4 text-sm">
                                <span className="text-muted-foreground">
                                    Movie ID
                                </span>
                                <span className="font-mono text-xs">
                                    #{moviePageData.id}
                                </span>
                            </div>
                            {displayedError && (
                                <p className="rounded-md bg-destructive/10 p-3 text-sm text-destructive">
                                    {displayedError}
                                </p>
                            )}
                            {moviePageData.can_prepare &&
                                !isPreparing &&
                                (moviePageData.conversion_status ===
                                    'pending' ||
                                    moviePageData.conversion_status ===
                                        'failed') && (
                                    <Button
                                        type="button"
                                        className="w-full"
                                        disabled={conversionForm.processing}
                                        onClick={startConversion}
                                    >
                                        {preparationFailed ? (
                                            <RotateCcw />
                                        ) : (
                                            <Play />
                                        )}
                                        {preparationFailed
                                            ? 'Retry'
                                            : 'Prepare video'}
                                    </Button>
                                )}
                        </CardContent>
                    </Card>

                    {moviePageData.movieData && (
                        <Card className="border-border/60 shadow-sm">
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-lg">
                                    <Info className="size-5" />
                                    About this movie
                                </CardTitle>
                                <CardDescription>
                                    Movie details from OMDb.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {moviePageData.movieData.poster && (
                                    <div className="flex justify-center">
                                        <img
                                            src={moviePageData.movieData.poster}
                                            alt={`${moviePageData.title}`}
                                            className="max-w-50 rounded-lg text-center shadow-md"
                                        />
                                    </div>
                                )}

                                <div className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                                    <div>
                                        <span className="text-muted-foreground">
                                            Year:
                                        </span>
                                        <span className="ml-2 font-medium">
                                            {moviePageData.movieData.year}
                                        </span>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">
                                            Rated:
                                        </span>
                                        <span className="ml-2 font-medium">
                                            {moviePageData.movieData.rated ??
                                                'N/A'}
                                        </span>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">
                                            Released:
                                        </span>
                                        <span className="ml-2 font-medium">
                                            {moviePageData.movieData.released ??
                                                'N/A'}
                                        </span>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">
                                            Runtime:
                                        </span>
                                        <span className="ml-2 font-medium">
                                            {moviePageData.movieData.runtime}
                                        </span>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">
                                            IMDB Rating :
                                        </span>
                                        <span className="ml-2 font-medium">
                                            {moviePageData.movieData
                                                .imdbRating ?? 'N/A'}
                                        </span>
                                    </div>
                                </div>

                                <div>
                                    <span className="text-muted-foreground">
                                        Genre:
                                    </span>
                                    <span className="ml-2 font-medium">
                                        {moviePageData.movieData.genre}
                                    </span>
                                </div>

                                <div>
                                    <span className="text-muted-foreground">
                                        Producer:
                                    </span>
                                    <span className="ml-2 font-medium">
                                        {moviePageData.movieData.producer}
                                    </span>
                                </div>

                                <div>
                                    <span className="text-muted-foreground">
                                        Director:
                                    </span>
                                    <span className="ml-2 font-medium">
                                        {moviePageData.movieData.director}
                                    </span>
                                </div>

                                <div>
                                    <span className="text-muted-foreground">
                                        Writer:
                                    </span>
                                    <span className="ml-2 font-medium">
                                        {moviePageData.movieData.writer}
                                    </span>
                                </div>

                                <div>
                                    <span className="text-muted-foreground">
                                        Actors:
                                    </span>
                                    <span className="ml-2 font-medium">
                                        {moviePageData.movieData.actors}
                                    </span>
                                </div>

                                <div>
                                    <span className="text-muted-foreground">
                                        Plot:
                                    </span>
                                    <p className="mt-1 text-muted-foreground">
                                        {moviePageData.movieData.plot}
                                    </p>
                                </div>

                                <div>
                                    <span className="text-muted-foreground">
                                        Language:
                                    </span>
                                    <span className="ml-2 font-medium">
                                        {moviePageData.movieData.language}
                                    </span>
                                </div>

                                {moviePageData.movieData.awards !== 'N/A' && (
                                    <div className="rounded-md bg-amber-50 p-3 text-sm text-amber-800">
                                        <strong>Awards:</strong>{' '}
                                        {moviePageData.movieData.awards}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {!moviePageData.movieData && (
                        <Card className="border-border/60 shadow-sm">
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-lg">
                                    <Info className="size-5" />
                                    About this movie
                                </CardTitle>
                                <CardDescription>
                                    A dedicated space for the movie details.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="rounded-lg border border-dashed bg-muted/30 p-5 text-sm text-muted-foreground">
                                    No additional information available for this
                                    movie.
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    <Card className="border-border/60 shadow-sm">
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-lg">
                                <MessageCircle className="size-5" />
                                Comments ({comments.meta.total})
                            </CardTitle>
                            <CardDescription>
                                Share your thoughts with other viewers.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            <CommentForm movieId={moviePageData.id} />

                            <div className="space-y-4">
                                {comments.data.length === 0 ? (
                                    <p className="py-4 text-center text-sm text-muted-foreground">
                                        No comments yet. Be the first to share
                                        your thoughts!
                                    </p>
                                ) : (
                                    <InfiniteScroll
                                        data="comments"
                                        buffer={300}
                                        onlyNext
                                    >
                                        {comments.data.map((comment) => (
                                            <CommentItem
                                                key={comment.id}
                                                comment={comment}
                                            ></CommentItem>
                                        ))}
                                    </InfiniteScroll>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

MovieShow.layout = ({ moviePageData }: MovieShowProps) => ({
    breadcrumbs: [{ title: moviePageData.title, href: show(moviePageData.id) }],
});
