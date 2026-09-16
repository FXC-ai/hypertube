import { Head, useForm, usePoll } from '@inertiajs/react';
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
import { manifest } from '@/routes/movies/hls';

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
};

const conversionStatusLabel: Record<ConversionStatus, string> = {
    pending: 'Ready to prepare',
    queued: 'Queued',
    converting: 'Preparing video',
    playable: 'Available',
    converted: 'Available',
    failed: 'Preparation failed',
};

function HlsPlayer({ src, preferredlanguage }: { src: string, preferredlanguage: string }) {

    const videoRef = useRef<HTMLVideoElement>(null);

    const languages: { [language: string]: string } = { "french": "fr", "german": "de", "english": "en", "italian": "it" }

    useEffect(() => {
        const video = videoRef.current;

        if (video === null) {
            return;
        }

        if (video.canPlayType('application/vnd.apple.mpegurl')) {
            video.src = src;

            return () => {
                video.removeAttribute('src');
                video.load();
            };
        }

        if (!Hls.isSupported()) {
            return;
        }

        const hls = new Hls({
            subtitlePreference: {
                lang: languages[preferredlanguage],
            },
        });
        hls.loadSource(src);
        hls.attachMedia(video);

        /*         hls.on(Hls.Events.MANIFEST_PARSED, function (_, data) {
        
                    const tracks = data.subtitleTracks;
                    console.log("SUBTITLE TRACKS = ", tracks);
        
                    const defaultTrackIndex = tracks.findIndex(track => track.lang === 'fr');
                    console.log("SUBTITLE TRACKS = ", defaultTrackIndex);
        
                    if (defaultTrackIndex !== -1) {
                        console.log("je rentre dans la condition")
                        hls.subtitleTrack = 1;
                    }
                    console.log(hls.subtitleTrack)
                }); */

        // 1. Attendre que les pistes de sous-titres soient chargées
        /*         hls.on(Hls.Events.SUBTITLE_TRACKS_UPDATED, function (event, data) {
        
                    // data.subtitleTracks contient la liste de tous les sous-titres disponibles
                    const tracks = data.subtitleTracks;
        
                    console.log("SUBTITLE TRACKS = ", tracks);
                    // 2. Trouver l'index de la langue souhaitée (ex: 'fr' pour le Français)
                    const defaultTrackIndex = tracks.findIndex(track => track.lang === 'fr');
        
                    console.log("SUBTITLE TRACKS = ", defaultTrackIndex);
        
        
                    console.log("defaulttrackIndex = ", defaultTrackIndex);
                    // 3. Activer la piste si elle existe
                    if (defaultTrackIndex !== -1) {
                        hls.subtitleDisplay = true;
                        hls.subtitleTrack = defaultTrackIndex;
                    }
                    console.log("defaulttrackIndex = ", hls.subtitleTrack);
        
        
                }); */


        hls.on(Hls.Events.ERROR, (_event, data) => {
            console.error('HLS error', {
                type: data.type,
                details: data.details,
                fatal: data.fatal,
                reason: data.reason,
                response: data.response,
                url: data.url,
            });
        });

        return () => {
            hls.destroy();
        };
    }, [src]);

    return (
        <video
            ref={videoRef}
            className="aspect-video w-full bg-black object-contain"
            controls
            preload="metadata"
        />
    );
}

export default function MovieShow({ moviePageData }: MovieShowProps) {
    const conversionForm = useForm({});
    const { stop } = usePoll(2000, {});
    const isPreparing = ['queued', 'converting'].includes(
        moviePageData.conversion_status,
    );
    const statusLabel = conversionStatusLabel[moviePageData.conversion_status];

    useEffect(() => {
        if (moviePageData.playable) {
            stop();
        }
    }, [moviePageData.playable, stop]);

    const startConversion = (): void => {
        conversionForm.post(conversionStore.url(moviePageData.id), {
            preserveScroll: true,
        });
    };

    return (
        <>
            <Head title={moviePageData.title} />
            <main className="min-h-full bg-background">
                <section className="border-b px-4 py-8 sm:px-6 lg:px-8">
                    <div className="mx-auto max-w-4xl">
                        <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">
                            {moviePageData.title}
                        </h1>
                    </div>
                </section>

                <div className="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                    <Card className="overflow-hidden border-border/60 bg-black p-0 shadow-xl">
                        {moviePageData.playable &&
                            moviePageData.conversion_attempt !== null ? (
                            <HlsPlayer
                                src={manifest.url({
                                    movie: moviePageData.id,
                                    conversion_attempt:
                                        moviePageData.conversion_attempt,
                                })}

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
                            {(moviePageData.conversion_status === 'pending' ||
                                moviePageData.conversion_status ===
                                'failed') && (
                                    <Button
                                        type="button"
                                        className="w-full"
                                        disabled={conversionForm.processing}
                                        onClick={startConversion}
                                    >
                                        {moviePageData.conversion_status ===
                                            'failed' ? (
                                            <RotateCcw />
                                        ) : (
                                            <Play />
                                        )}
                                        {moviePageData.conversion_status ===
                                            'failed'
                                            ? 'Retry'
                                            : 'Prepare video'}
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
                                Comments
                            </CardTitle>
                            <CardDescription>
                                Share your thoughts with other viewers.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="rounded-lg border border-dashed bg-muted/30 p-5 text-sm text-muted-foreground">
                                Comments and the writing area will be available
                                soon.
                            </div>
                        </CardContent>
                    </Card>


                </div>
            </main>
        </>
    );
}

MovieShow.layout = ({ moviePageData }: MovieShowProps) => ({
    breadcrumbs: [{ title: moviePageData.title, href: show(moviePageData.id) }],
});
