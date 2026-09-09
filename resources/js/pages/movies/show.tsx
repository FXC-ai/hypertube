import { manifest } from "@/routes/movies/hls";
import { store as conversionStore } from "@/routes/movies/conversion";
import { show } from "@/routes/movies";
import { useForm, usePoll } from "@inertiajs/react";
import { useEffect, useRef } from "react";

import Hls from 'hls.js';

type ConversionStatus = 'pending' | 'queued' | 'converting' | 'playable' | 'converted' | 'failed';

type MoviePageData = {
    id: number;
    title: string;
    filename: string;
    conversion_status: ConversionStatus;
    conversion_error: string | null;
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

        return () => {
            hls.destroy();
        };
    }, [src]);

    return <video ref={videoRef} controls preload="metadata" />;
}

export default function MovieShow({ moviePageData }: MovieShowProps) {

    const conversionForm = useForm({});
    const startConversion = (): void => { console.log("start conversion"); conversionForm.post(conversionStore.url(moviePageData.id), { preserveScroll: true }); };

    const { start: startPolling, stop: stopPolling } = usePoll(2000, { only: ['movie'] }, { autoStart: false, mode: 'rest' });
    const shouldPoll = moviePageData.conversion_status === 'queued' || moviePageData.conversion_status === 'converting' || moviePageData.conversion_status === 'playable';

    useEffect(
        () => {
            if (shouldPoll) { startPolling(); }
            else { stopPolling(); }

            return stopPolling;
        },
        [shouldPoll, startPolling, stopPolling]
    );

    return <main>
        <p>title = {moviePageData.title}</p>

        {moviePageData.playable && (<HlsPlayer src={manifest.url(moviePageData.id)} />)}

        {
            (moviePageData.conversion_status === 'pending' || moviePageData.conversion_status === 'failed') && (
                <button
                    type="button"
                    disabled={conversionForm.processing}
                    onClick={startConversion}
                >
                    {moviePageData.conversion_status === 'failed' ? 'Réessayer' : 'Watch movie'}
                </button>
            )
        }

        {moviePageData.conversion_status === 'queued' && (<p>Conversion en attente…</p>)}

        {moviePageData.conversion_status === 'converting' && (<p>Préparation de la vidéo…</p>)}

        {moviePageData.conversion_status === 'playable' && (<p>La lecture est disponible ; la conversion continue.</p>)}

        {moviePageData.conversion_status === 'converted' && (<p>Conversion terminée.</p>)}

        {moviePageData.conversion_status === 'failed' && moviePageData.conversion_error !== null && (<p>Échec de la conversion : {moviePageData.conversion_error}</p>)}


        <p>id = {moviePageData.id}</p>
        <p>filename = {moviePageData.filename}</p>
        <p>conversion_status = {moviePageData.conversion_status}</p>
        <p>conversion_error = {moviePageData.conversion_error}</p>
        <p>playable = {String(moviePageData.playable)}</p>
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

