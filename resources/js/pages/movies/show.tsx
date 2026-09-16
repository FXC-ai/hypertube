import { manifest } from "@/routes/movies/hls";
import { store as conversionStore } from "@/routes/movies/conversion";
import { show as conversionShow } from "@/routes/movies/conversion";
import { show } from "@/routes/movies";
import { useForm, usePoll } from "@inertiajs/react";
import { useEffect, useRef, useState } from "react";
import { router } from "@inertiajs/react";

import Hls from 'hls.js';

type ConversionStatus = 'pending' | 'queued' | 'converting' | 'playable' | 'converted' | 'failed';

type MoviePageData = {
    id: number;
    title: string;
    filename: string;
    conversion_attempt: string | null;
    conversion_status: ConversionStatus;
    conversion_error: string | null;
    playable: boolean;
};

type Conversion = {
    attempt: string | null;
    status: ConversionStatus;
    error: string | null;
    playable: boolean;
};

type MovieShowProps = {
    moviePageData: MoviePageData;
}

function HlsPlayer({ src }: { src: string }) {
    const videoRef = useRef<HTMLVideoElement>(null);

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

        const hls = new Hls();
        hls.loadSource(src);
        hls.attachMedia(video);

        hls.on(Hls.Events.MANIFEST_PARSED, () => {
            const preferredLanguage = 'fr';
            const subtitleIndex = hls.subtitleTracks.findIndex(
                (track) => track.lang === preferredLanguage,
            );

            hls.subtitleTrack = subtitleIndex;
        });

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



        hls.on(Hls.Events.MANIFEST_LOADED, (_event, data) => {
            console.log('HLS manifest loaded', data);
        });

        hls.on(Hls.Events.MANIFEST_PARSED, (_event, data) => {
            console.log('HLS manifest parsed', data);
        });

        return () => {
            hls.destroy();
        };
    }, [src]);

    return <video ref={videoRef} controls preload="metadata" />;
}







export default function MovieShow({ moviePageData }: MovieShowProps) {

    const conversionForm = useForm({});
    const startConversion = (): void => { conversionForm.post(conversionStore.url(moviePageData.id), { preserveScroll: true }); };

    const { stop } = usePoll(2000, {});

    useEffect(() => { if (moviePageData.playable) { stop() } }, [moviePageData.playable, stop])

    return <main>

        {moviePageData.playable && moviePageData.conversion_attempt !== null && (
            <HlsPlayer
                src={manifest.url({
                    movie: moviePageData.id,
                    conversion_attempt: moviePageData.conversion_attempt,
                })}
            />
        )}

        {
            (moviePageData.conversion_status === 'pending' || moviePageData.conversion_status === 'failed') && (
                <button
                    type="button"
                    disabled={conversionForm.processing}
                    onClick={startConversion}
                >
                    {moviePageData.conversion_status === 'failed' ? 'Réessayer' : 'Watch movieeee'}
                </button>
            )
        }


        {moviePageData.playable === true && (<p>The movie is avaible for watching.</p>)}


        <p>===============================================================</p>

        <p>id = {moviePageData.id}</p>
        <p>title = {moviePageData.title}</p>
        <p>filename = {moviePageData.filename}</p>

        <p>===============================================================</p>

    </main>;

}


MovieShow.layout = ({ moviePageData }: { moviePageData: MovieShowProps }) => ({
    breadcrumbs: [
        {
            title: 'Show Movie',
            href: show(moviePageData.id),
        },
    ],
});

