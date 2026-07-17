FROM node:22

WORKDIR /app

# Install dependencies first (better Docker layer caching)
COPY package*.json ./
RUN npm install

# Copy the rest of the project
COPY . .

EXPOSE 5173

CMD ["npm", "run", "dev", "--", "--host"]
