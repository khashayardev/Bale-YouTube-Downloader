FROM ubuntu:latest

RUN apt-get update -qq && \
    apt-get install -y -qq \
    python3-pip \
    ffmpeg \
    zip p7zip-full \
    curl jq bc unzip \
    && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL https://deno.land/install.sh | sh && \
    mv /root/.deno/bin/deno /usr/local/bin/deno

RUN pip3 install --upgrade yt-dlp --quiet --break-system-packages
