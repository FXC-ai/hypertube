import { show } from "@/routes/movies";

import { watch } from "@/routes/movies";
import { usePage } from "@inertiajs/react";

export type Movie = {
    id: number;
    title: string;
    filename: string;
    filepath: string;

};

type MovieShowProps = {
    movie: Movie;
}

export default function MovieShow({ movie }: MovieShowProps) {


    console.log("test = ", movie);

    return (
        <>
            <h1>Movie : </h1>
            <p>{movie.id}</p>
            <p>{movie.title}</p>
            <p>{movie.filename}</p>
            <p>{movie.filepath}</p>

            <video width="640" height="360" controls>
                <source src={watch.url(movie.id)} type="video/mp4" />
                Votre navigateur ne supporte pas la lecture de vidéos.
            </video>

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
