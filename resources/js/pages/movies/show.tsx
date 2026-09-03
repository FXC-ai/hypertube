import { show } from "@/routes/movies";

import { watch } from "@/routes/movies";
import { usePage } from "@inertiajs/react";

import { useRef, useState, useEffect } from "react";
import Hls from 'hls.js';
import { manifest } from "@/routes/movies/hls";

import { encode } from "@/routes/movies";
import { Link } from "@inertiajs/react";


export type Movie = {
    id: number;
    title: string;
    filename: string;
    filepath: string;

};

type MovieShowProps = {
    movie: Movie;
}


type HlsPlayerProps = {
    src: string;
};

function HlsPlayer({ src }: HlsPlayerProps) {
    const videoRef = useRef<HTMLVideoElement>(null);

    useEffect(() => {
        const video = videoRef.current;

        if (!video) {
            return;
        }

        if (video.canPlayType('application/vnd.apple.mpegurl')) {
            video.src = src;
            return;
        }

        if (!Hls.isSupported()) {
            return;
        }

        const hls = new Hls();

        hls.loadSource(src);
        hls.attachMedia(video);

        return () => {
            hls.destroy();
        };
    }, [src]);

    return <video ref={videoRef} controls preload="metadata" />;
}


export default function MovieShow({ movie }: MovieShowProps) {


    console.log("test = ", movie);

    /*     <video width="640" height="360" controls preload="metadata">
            <source src={watch.url(movie.id)} type="video/mp4" />
            Votre navigateur ne supporte pas la lecture de vidéos.
        </video> */

    return (
        <>
            <h1>Movie : </h1>
            <p>{movie.id}</p>
            <p>{movie.title}</p>
            <p>{movie.filename}</p>
            <p>{movie.filepath}</p>


            <Link href={encode.url(movie.id)}>Watch movie</Link>
            {/* {<HlsPlayer src={manifest.url(movie.id)}></HlsPlayer>} */}

        </>
    );
}


MovieShow.layout = ({ movie }: MovieShowProps) => ({
    breadcrumbs: [
        {
            title: 'Show Movie',
            href: show(movie.id),
        },
    ],
});

