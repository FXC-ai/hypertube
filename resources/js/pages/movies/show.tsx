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
import { useEffect, useRef } from 'react';

import CommentForm from '@/components/comment-form';
import CommentItem from '@/components/comment-item';
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

type MoviePageData = {
    id: number;
    title: string;
    filename: string;
    conversion_attempt: string | null;
    conversion_status: ConversionStatus;
    conversion_error: string | null;
    playable: boolean;
    preferredlanguage: string;
};

type MovieShowProps = {
    moviePageData: MoviePageData;
    comments: { data: Comment[], meta: {total: number} };
};

const conversionStatusLabel: Record<ConversionStatus, string> = {
    pending: 'Ready to prepare',
    queued: 'Queued',
    converting: 'Preparing video',
    playable: 'Available',
    converted: 'Available',
    failed: 'Preparation failed',
};

function HlsPlayer({ src, preferredlanguage }: { src: string; preferredlanguage: string }) {

    const videoRef = useRef<HTMLVideoElement>(null);

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

        const supportsMediaSource = Hls.isSupported();
        const supportsNativeHls = video.canPlayType('application/vnd.apple.mpegurl') !== '';

        if (!supportsMediaSource && supportsNativeHls) {
            video.src = src;

            return () => {
                video.removeAttribute('src');
                video.load();
            };
        }

        if (!supportsMediaSource) {
            console.error('HLS is not supported by this browser');

            return;
        }

        const hls = new Hls(
            {
                autoStartLoad: false,
                subtitlePreference: {
                    lang: languages[preferredlanguage],
                },
                lowLatencyMode: false,
                startPosition: 0,
            }
        );

        hls.on(Hls.Events.MEDIA_ATTACHED, () => {
            hls.loadSource(src);
            hls.startLoad(0);
        });

        hls.on(Hls.Events.MANIFEST_PARSED, (_event, data) => {
            console.log('HLS manifest parsed', data);
        });

        hls.on(Hls.Events.LEVEL_LOADED, (_event, data) => {
            console.log('Playlist HLS chargée', {
                live: data.details.live,
                segments: data.details.fragments.length,
                firstSegment: data.details.fragments[0]?.sn,
                lastSegment: data.details.fragments.at(-1)?.sn,
                duration: data.details.totalduration,
                endSN: data.details.endSN,
                totalduration: data.details.totalduration,
                hasEndList: data.details.live === false
            });
        });

        hls.on(Hls.Events.FRAG_LOADED, (_event, data) => {
            console.log('HLS fragment loaded', data.frag.sn);
        });

        hls.on(Hls.Events.ERROR, (_event, data) => {
            console.error('HLS error', {
                type: data.type,
                details: data.details,
                fatal: data.fatal,
                url: data.url,
                status: data.response?.code,
                text: data.response?.text,
            });

            if (data.fatal && data.type === Hls.ErrorTypes.MEDIA_ERROR) {
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
        <video
            ref={videoRef}
            className="aspect-video w-full bg-black object-contain"
            controls
            preload="metadata"
        />
    );
}

export default function MovieShow({ moviePageData, comments }: MovieShowProps) {

    const conversionForm = useForm({});
    const { stop } = usePoll(2000, {});
    const isPreparing = ['queued', 'converting'].includes(
        moviePageData.conversion_status,
    );
    const statusLabel = conversionStatusLabel[moviePageData.conversion_status];

    useEffect(() => {
        if (
            moviePageData.conversion_status === 'playable' ||
            moviePageData.conversion_status === 'failed'
        ) {
            stop();
        }
    }, [moviePageData.conversion_status, stop]);

    const startConversion = (): void => {
        conversionForm.post(conversionStore.url(moviePageData.id), { preserveScroll: true });
    };

    console.log("moviePageData.conversion_attempt = ", moviePageData.conversion_attempt);
    return (
        <>
            <Head title={moviePageData.title} />
            <div className="w-full bg-background">
                <section className="border-b px-4 py-8 sm:px-6 lg:px-8">
                    <div className="mx-auto max-w-4xl">
                        <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">
                            {moviePageData.title}
                        </h1>
                    </div>
                </section>

                <div className="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                    <Card className="overflow-hidden border-border/60 bg-black p-0 shadow-xl">
                        {moviePageData.playable && moviePageData.conversion_attempt !== null ?
                            (
                                <HlsPlayer
                                    /*                                 src={manifest.url({
                                                                        movie: moviePageData.id,
                                                                        conversion_attempt: moviePageData.conversion_attempt
                                                                    })} */


                                    src={`/storage/movies/${moviePageData.id}/hls/${moviePageData.conversion_attempt}/index.m3u8`}
                                    preferredlanguage={moviePageData.preferredlanguage}
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
                                            {isPreparing
                                                ? 'The video is being prepared'
                                                : 'The video is not available yet'}
                                        </p>
                                        <p className="mt-1 text-sm">
                                            {isPreparing
                                                ? 'This page will update automatically.'
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
                            {moviePageData.conversion_error && (
                                <p className="rounded-md bg-destructive/10 p-3 text-sm text-destructive">
                                    {moviePageData.conversion_error}
                                </p>
                            )}
                            {(moviePageData.conversion_status === 'pending' || moviePageData.conversion_status === 'failed') &&
                                (
                                    <Button
                                        type="button"
                                        className="w-full"
                                        disabled={conversionForm.processing}
                                        onClick={startConversion}
                                    >
                                        {moviePageData.conversion_status === 'failed' ? (<RotateCcw />) : (<Play />)}
                                        {moviePageData.conversion_status === 'failed' ? 'Retry' : 'Prepare video'}
                                    </Button>
                                )}
                        </CardContent>
                    </Card>

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
                                Genres, runtime, description, and credits will
                                appear here.
                            </div>
                        </CardContent>
                    </Card>

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
                                    <p className="text-sm text-muted-foreground text-center py-4">
                                        No comments yet. Be the first to share your thoughts!
                                    </p>
                                ) : (
                                    <InfiniteScroll data="comments" buffer={300} onlyNext>

                                        {
                                            comments.data.map((comment) => (<CommentItem key={comment.id} comment={comment}></CommentItem>))
                                        }

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
